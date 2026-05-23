'use strict';

/**
 * Вебхук «Сообщение — API» (BOT-T) — sendGift.
 *
 * Параметры URL: bot_id, token, gift_id, admin_id (необязательно).
 * Тело: JSON с user_id, telegram_id, message_id.
 *
 * Запуск: node javascript/gift-send/index-message.js
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

async function adminNotifyUser(adminId, token, userId, telegramId, giftId, success, reason = '') {
  if (adminId == null) return;
  const tgLine = telegramId != null ? `\nTelegram: ${telegramId}` : '';
  const text = success
    ? `Подарок отправлен пользователю.\nПользователь бота: #${userId}${tgLine}\nПодарок: ${giftId}`
    : `Не удалось отправить подарок.\nПользователь бота: #${userId}${tgLine}\nПодарок: ${giftId}\nПричина: ${reason}`;
  await notifyAdminPm(token, adminId, text);
}

async function handler(req, res) {
  if (req.method !== 'POST') {
    sendJson(res, { ok: false, error: 'Method not allowed' }, 405);
    return;
  }

  const query = parseQuery(req.url);
  const { bot_id: botId, token, gift_id: giftId } = query;
  const adminId = parseAdminId(query);
  if (!botId || !token || !giftId) {
    sendJson(res, { ok: false, error: 'Required query: bot_id, token, gift_id' }, 400);
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

  const userId = Number(userIdRaw);
  const messageId = Number(messageIdRaw);
  const telegramId = (telegramIdRaw != null && telegramIdRaw !== '' && /^-?\d+$/.test(String(telegramIdRaw)))
    ? Number(telegramIdRaw)
    : null;

  const sentMarker = path.join(DIR, `sent_msg_${messageId}_${userId}.lock`);
  if (fs.existsSync(sentMarker)) {
    sendJson(res, { ok: true, skipped: true, reason: 'already_sent' });
    return;
  }

  const url = `https://api.bot-t.com/v1/bot/user/send-request?token=${encodeURIComponent(token)}`;
  const { json } = await postJson(url, {
    bot_id: Number(botId),
    user_id: userId,
    method: 'sendGift',
    params: { gift_id: String(giftId) },
  });

  if (!json) {
    await adminNotifyUser(adminId, token, userId, telegramId, String(giftId), false, 'BOT-T API request failed');
    sendJson(res, { ok: false, error: 'BOT-T API request failed' }, 502);
    return;
  }

  if (!json.result) {
    const message = json.message || 'BOT-T API error';
    await adminNotifyUser(adminId, token, userId, telegramId, String(giftId), false, message);
    sendJson(res, { ok: false, error: message }, 502);
    return;
  }

  fs.writeFileSync(sentMarker, new Date().toISOString(), 'utf8');
  await adminNotifyUser(adminId, token, userId, telegramId, String(giftId), true);
  sendJson(res, { ok: true, user_id: userId, message_id: messageId });
}

if (require.main === module) {
  runServer(handler, 9015);
}

module.exports = { handler };
