'use strict';

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Начисляет отчисление другому пользователю бота (не покупателю): фиксированный bot_user_id
 * из URL, сумма = amount заказа (копейки) × coef.
 *
 * Параметры URL: bot_id, token, bot_user_id, coef.
 * Тело вебхука: id (заказ), amount.
 *
 * Запуск: node javascript/bonususer/index.js
 */

const {
  parseQuery,
  parsePostForm,
  postFormUrlencoded,
  sendText,
  runServer,
} = require('../common');

async function handler(req, res) {
  if (req.method !== 'POST') {
    sendText(res, 'Method not allowed', 405);
    return;
  }

  const query = parseQuery(req.url);
  const post = await parsePostForm(req);

  const { bot_id: botId, token, bot_user_id: botUserId, coef } = query;
  const orderId = post.id;
  if (orderId == null || orderId === '') {
    sendText(res, 'not found order_id');
    return;
  }

  let amount = Math.trunc(Number(post.amount) * Number(coef));

  const url = `https://api.bot-t.com/v1/bot/user/add-balance?token=${encodeURIComponent(token)}`;
  await postFormUrlencoded(url, {
    bot_id: botId,
    user_id: botUserId,
    sum: Math.round((amount / 100) * 100) / 100,
    comment: `Начисление отчисления от заказа${orderId}`,
  });

  sendText(res, '');
}

if (require.main === module) {
  runServer(handler, 9012);
}

module.exports = { handler };
