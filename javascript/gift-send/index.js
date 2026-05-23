'use strict';

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T).
 *
 * Отправляет покупателю Telegram-подарок через BOT-T API (method sendGift).
 * Повторный вебхук с тем же id заказа не дублирует отправку (файл sent_{id}.lock).
 *
 * Параметры URL: bot_id, token, gift_id.
 * Тело вебхука: id (заказ), status (только status=1).
 *
 * Запуск: node javascript/gift-send/index.js
 */

const path = require('path');
const {
  parseQuery,
  parsePostForm,
  postJson,
  sendJson,
  runServer,
  fs,
} = require('../common');

const DIR = __dirname;

async function handler(req, res) {
  if (req.method !== 'POST') {
    sendJson(res, { ok: false, error: 'Method not allowed' }, 405);
    return;
  }

  const query = parseQuery(req.url);
  const post = await parsePostForm(req);

  const { bot_id: botId, token, gift_id: giftId } = query;
  if (!botId || !token || !giftId) {
    sendJson(res, { ok: false, error: 'Required query: bot_id, token, gift_id' }, 400);
    return;
  }

  const orderIdRaw = post.id;
  if (orderIdRaw == null || orderIdRaw === '') {
    sendJson(res, { ok: false, error: 'Missing order id in webhook' }, 400);
    return;
  }

  const orderId = Number(orderIdRaw);
  const status = Number(post.status ?? -1);

  if (status !== 1) {
    sendJson(res, { ok: true, skipped: true, reason: 'status_not_paid' });
    return;
  }

  const sentMarker = path.join(DIR, `sent_${orderId}.lock`);
  if (fs.existsSync(sentMarker)) {
    sendJson(res, { ok: true, skipped: true, reason: 'already_sent' });
    return;
  }

  const url = `https://api.bot-t.com/v1/shop/order/send-request?token=${encodeURIComponent(token)}`;
  const { json } = await postJson(url, {
    bot_id: Number(botId),
    order_id: orderId,
    method: 'sendGift',
    params: { gift_id: String(giftId) },
  });

  if (!json || !json.result) {
    const message = (json && json.message) || 'BOT-T API error';
    sendJson(res, { ok: false, error: message }, 502);
    return;
  }

  fs.writeFileSync(sentMarker, new Date().toISOString(), 'utf8');
  sendJson(res, { ok: true, order_id: orderId });
}

if (require.main === module) {
  runServer(handler, 9014);
}

module.exports = { handler };
