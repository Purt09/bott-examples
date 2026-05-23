'use strict';

/**
 * Вебхук «уведомление после оплаты заказа» (BOT-T) — transferGift.
 *
 * Параметры URL: bot_id, token, owned_gift_id, business_connection_id,
 * admin_id и star_count — необязательно.
 *
 * Запуск: node javascript/gift-send/index-transfer.js
 */

const path = require('path');
const {
  parseQuery,
  parsePostForm,
  postJson,
  postFormUrlencoded,
  sendJson,
  runServer,
  fs,
} = require('../common');

const DIR = __dirname;

function parseAdminId(query) {
  const raw = query.admin_id;
  if (raw == null || raw === '' || !/^-?\d+$/.test(String(raw))) {
    return null;
  }
  return String(raw);
}

function parseStarCount(query) {
  const raw = query.star_count;
  if (raw == null || raw === '' || !/^\d+$/.test(String(raw))) {
    return null;
  }
  return Number(raw);
}

function buildTransferParams(businessConnectionId, ownedGiftId, newOwnerChatId, starCount) {
  const params = {
    business_connection_id: businessConnectionId,
    owned_gift_id: ownedGiftId,
    new_owner_chat_id: newOwnerChatId,
  };
  if (starCount != null && starCount > 0) {
    params.star_count = starCount;
  }
  return params;
}

async function notifyAdminPm(token, telegramId, text) {
  const url = `https://api.telegram.org/bot${token}/sendMessage`;
  await postFormUrlencoded(url, { chat_id: telegramId, text });
}

async function adminNotifyOrder(adminId, token, orderId, ownedGiftId, success, reason = '') {
  if (adminId == null) return;
  const text = success
    ? `Коллекционный подарок передан покупателю.\nЗаказ: #${orderId}\nowned_gift_id: ${ownedGiftId}`
    : `Не удалось передать коллекционный подарок.\nЗаказ: #${orderId}\nowned_gift_id: ${ownedGiftId}\nПричина: ${reason}`;
  await notifyAdminPm(token, adminId, text);
}

async function handler(req, res) {
  if (req.method !== 'POST') {
    sendJson(res, { ok: false, error: 'Method not allowed' }, 405);
    return;
  }

  const query = parseQuery(req.url);
  const post = await parsePostForm(req);

  const {
    bot_id: botId,
    token,
    owned_gift_id: ownedGiftId,
    business_connection_id: businessConnectionId,
  } = query;
  const adminId = parseAdminId(query);
  const starCount = parseStarCount(query);

  if (!botId || !token || !ownedGiftId || !businessConnectionId) {
    sendJson(res, {
      ok: false,
      error: 'Required query: bot_id, token, owned_gift_id, business_connection_id',
    }, 400);
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

  const telegramIdRaw = post.botUser?.user?.telegram_id;
  if (telegramIdRaw == null || telegramIdRaw === '' || !/^-?\d+$/.test(String(telegramIdRaw))) {
    sendJson(res, { ok: false, error: 'Missing botUser[user][telegram_id] in webhook' }, 400);
    return;
  }

  const newOwnerChatId = Number(telegramIdRaw);

  const sentMarker = path.join(DIR, `sent_transfer_${orderId}.lock`);
  if (fs.existsSync(sentMarker)) {
    sendJson(res, { ok: true, skipped: true, reason: 'already_sent' });
    return;
  }

  const url = `https://api.bot-t.com/v1/shop/order/send-request?token=${encodeURIComponent(token)}`;
  const { json } = await postJson(url, {
    bot_id: Number(botId),
    order_id: orderId,
    method: 'transferGift',
    params: buildTransferParams(
      String(businessConnectionId),
      String(ownedGiftId),
      newOwnerChatId,
      starCount,
    ),
  });

  if (!json) {
    await adminNotifyOrder(adminId, token, orderId, String(ownedGiftId), false, 'BOT-T API request failed');
    sendJson(res, { ok: false, error: 'BOT-T API request failed' }, 502);
    return;
  }

  if (!json.result) {
    const message = json.message || 'BOT-T API error';
    await adminNotifyOrder(adminId, token, orderId, String(ownedGiftId), false, message);
    sendJson(res, { ok: false, error: message }, 502);
    return;
  }

  fs.writeFileSync(sentMarker, new Date().toISOString(), 'utf8');
  await adminNotifyOrder(adminId, token, orderId, String(ownedGiftId), true);
  sendJson(res, { ok: true, order_id: orderId });
}

if (require.main === module) {
  runServer(handler, 9016);
}

module.exports = { handler };
