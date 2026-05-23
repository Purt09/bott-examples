'use strict';

/**
 * Вебхук «проверка товара перед выдачей» (BOT-T).
 * Ответ: {"success": true} — строка склада закрепляется за заказом.
 *
 * Запуск: node javascript/product-check/accept.js
 */

const { parsePostForm, sendJson, runServer } = require('../common');

async function handler(req, res) {
  if (req.method !== 'POST') {
    sendJson(res, { success: false, error: 'Method not allowed' }, 405);
    return;
  }

  const post = await parsePostForm(req);
  const product = post.product;

  if (product == null || product === '') {
    sendJson(res, { success: false });
    return;
  }

  sendJson(res, { success: true });
}

if (require.main === module) {
  runServer(handler, 9015);
}

module.exports = { handler };
