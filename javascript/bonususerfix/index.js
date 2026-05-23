'use strict';

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Начисляет другому пользователю бота фиксированную сумму (amount в копейках из URL),
 * не зависящую от суммы заказа. Номер заказа попадает только в комментарий.
 *
 * Параметры URL: bot_id, token, bot_user_id, amount (копейки).
 * Тело вебхука: id (заказ).
 *
 * Запуск: node javascript/bonususerfix/index.js
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

  const { bot_id: botId, token, bot_user_id: botUserId, amount } = query;
  const orderId = post.id;
  if (orderId == null || orderId === '') {
    sendText(res, 'not found order_id');
    return;
  }

  let sumKopecks = Math.trunc(Number(amount));

  const url = `https://api.bot-t.com/v1/bot/user/add-balance?token=${encodeURIComponent(token)}`;
  await postFormUrlencoded(url, {
    bot_id: botId,
    user_id: botUserId,
    sum: Math.round((sumKopecks / 100) * 100) / 100,
    comment: `Начисление отчисления от заказа фиксированного, номер заказа: ${orderId}`,
  });

  sendText(res, '');
}

if (require.main === module) {
  runServer(handler, 9013);
}

module.exports = { handler };
