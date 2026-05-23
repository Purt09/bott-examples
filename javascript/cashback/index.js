'use strict';

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Начисляет покупателю cashback на внутренний баланс бота: сумма заказа (amount, копейки)
 * умножается на coef и зачисляется через API add-balance.
 *
 * Параметры URL: bot_id, token, coef.
 * Тело вебхука: id (заказ), amount, botUser[id].
 *
 * Запуск: node javascript/cashback/index.js
 * URL: http://127.0.0.1:9010/?bot_id=1&token=TOKEN&coef=0.05
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

  const { bot_id: botId, token, coef } = query;
  const orderId = post.id;
  if (orderId == null || orderId === '') {
    sendText(res, 'not found order_id');
    return;
  }

  const botUserId = (post.botUser && post.botUser.id) || null;
  let amount = Math.trunc(Number(post.amount) * Number(coef));

  const url = `https://api.bot-t.com/v1/bot/user/add-balance?token=${encodeURIComponent(token)}`;
  await postFormUrlencoded(url, {
    bot_id: botId,
    user_id: botUserId,
    sum: Math.round((amount / 100) * 100) / 100,
    comment: `Начисление cashback системы от заказа${orderId}`,
  });

  sendText(res, '');
}

if (require.main === module) {
  runServer(handler, 9010);
}

module.exports = { handler };
