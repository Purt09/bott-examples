'use strict';

/**
 * Вебхук «Сообщение — API» (BOT-T) — transferGift.
 *
 * Параметры URL: bot_id, token, owned_gift_id, business_connection_id,
 * admin_id и star_count — необязательно.
 * Тело: JSON с user_id, telegram_id, message_id.
 *
 * Запуск: node javascript/gift-send/index-message-transfer.js
 */

const path = require('path');
const {
  parseQuery,
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

async function readJsonBody(req) {
  const chunks = [];
  await new Promise((resolve, reject) => {
    req.on('data', (c) => chunks.push(c));
    req.on('end', resolve);
    req.on('error', reject);
  });
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

async function notifyAdminPm(token, telegramId, text) {
  const url = `https://api.telegram.org/bot${token}/sendMessage`;
  await postFormUrlencoded(url, { chat_id: telegramId, text });
}

async function adminNotifyUser(adminId, token, userId, telegramId, ownedGiftId, success, reason = '') {
  if (adminId == null) return;
  const text = success
    ? `Коллекционный подарок передан пользователю.\nПользователь бота: #${userId}\nTelegram: ${telegramId}\nowned_gift_id: ${ownedGiftId}`
    : `Не удалось передать коллекционный подарок.\nПользователь бота: #${userId}\nTelegram: ${telegramId}\nowned_gift_id: ${ownedGiftId}\nПричина: ${reason}`;
  await notifyAdminPm(token, adminId, text);
}

async function handler(req, res) {
  if (req.method !== 'POST') {
    sendJson(res, { ok: false, error: 'Method not allowed' }, 405);
    return;
  }

  const query = parseQuery(req.url);
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

  let body;
  try {
    body = await readJsonBody(req);
  } catch {
    sendJson(res, { ok: false, error: 'Invalid JSON body' }, 400);
    return;
  }

  const userIdRaw = body.user_id;
  const messageIdRaw = body.message_id;
  const telegramIdRaw = body.telegram_id;

  if (userIdRaw == null || userIdRaw === '' || !/^\d+$/.test(String(userIdRaw))) {
    sendJson(res, { ok: false, error: 'Missing or invalid user_id in body' }, 400);
    return;
  }
  if (messageIdRaw == null || messageIdRaw === '' || !/^\d+$/.test(String(messageIdRaw))) {
    sendJson(res, { ok: false, error: 'Missing or invalid message_id in body' }, 400);
    return;
  }
  if (telegramIdRaw == null || telegramIdRaw === '' || !/^-?\d+$/.test(String(telegramIdRaw))) {
    sendJson(res, { ok: false, error: 'Missing or invalid telegram_id in body' }, 400);
    return;
  }

  const userId = Number(userIdRaw);
  const messageId = Number(messageIdRaw);
  const newOwnerChatId = Number(telegramIdRaw);

  const sentMarker = path.join(DIR, `sent_msg_transfer_${messageId}_${userId}.lock`);
  if (fs.existsSync(sentMarker)) {
    sendJson(res, { ok: true, skipped: true, reason: 'already_sent' });
    return;
  }

  const url = `https://api.bot-t.com/v1/bot/user/send-request?token=${encodeURIComponent(token)}`;
  const { json } = await postJson(url, {
    bot_id: Number(botId),
    user_id: userId,
    method: 'transferGift',
    params: buildTransferParams(
      String(businessConnectionId),
      String(ownedGiftId),
      newOwnerChatId,
      starCount,
    ),
  });

  if (!json) {
    await adminNotifyUser(adminId, token, userId, newOwnerChatId, String(ownedGiftId), false, 'BOT-T API request failed');
    sendJson(res, { ok: false, error: 'BOT-T API request failed' }, 502);
    return;
  }

  if (!json.result) {
    const message = json.message || 'BOT-T API error';
    await adminNotifyUser(adminId, token, userId, newOwnerChatId, String(ownedGiftId), false, message);
    sendJson(res, { ok: false, error: message }, 502);
    return;
  }

  fs.writeFileSync(sentMarker, new Date().toISOString(), 'utf8');
  await adminNotifyUser(adminId, token, userId, newOwnerChatId, String(ownedGiftId), true);
  sendJson(res, { ok: true, user_id: userId, message_id: messageId });
}

if (require.main === module) {
  runServer(handler, 9017);
}

module.exports = { handler };
