'use strict';

/**
 * Вебхук «проверка товара перед выдачей» (BOT-T).
 * Ответ: {"success": false} — строка снимается с продажи.
 *
 * Запуск: node javascript/product-check/reject.js
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

  sendJson(res, { success: false });
}

if (require.main === module) {
  runServer(handler, 9016);
}

module.exports = { handler };
