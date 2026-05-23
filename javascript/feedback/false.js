'use strict';

/**
 * Обработчик формы обратной связи BOT-T (пример отклонения).
 *
 * Запуск: node javascript/feedback/false.js
 */

const { parsePostForm, sendJson, runServer } = require('../common');

async function handler(req, res) {
  const post = await parsePostForm(req);
  const message = JSON.stringify(post);
  sendJson(res, {
    result: false,
    message: `Пример answer:${message}`,
  });
}

if (require.main === module) {
  runServer(handler, 9017);
}

module.exports = { handler };
