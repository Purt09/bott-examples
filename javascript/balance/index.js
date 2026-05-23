'use strict';

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Начисляет покупателю баланс за каждую единицу товара в заказе: count × 100 копеек × coef,
 * затем зачисление через API add-balance (сумма в рублях).
 *
 * Параметры URL: bot_id, token, coef.
 * Тело вебхука: count, botUser[id].
 *
 * Запуск: node javascript/balance/index.js
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
  const count = post.count;
  if (count == null || count === '') {
    sendText(res, 'not found count');
    return;
  }

  const botUserId = (post.botUser && post.botUser.id) || null;
  let amount = Math.trunc(Number(count) * 100 * Number(coef));

  const url = `https://api.bot-t.com/v1/bot/user/add-balance?token=${encodeURIComponent(token)}`;
  await postFormUrlencoded(url, {
    bot_id: botId,
    user_id: botUserId,
    sum: Math.round((amount / 100) * 100) / 100,
  });

  sendText(res, '');
}

if (require.main === module) {
  runServer(handler, 9011);
}

module.exports = { handler };
