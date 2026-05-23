'use strict';

/**
 * Обработчик формы обратной связи BOT-T (пример принятия с повтором).
 *
 * Запуск: node javascript/feedback/repeat-and-message.js
 */

const { parsePostForm, sendJson, runServer } = require('../common');

async function handler(req, res) {
  const post = await parsePostForm(req);
  const message = JSON.stringify(post);
  sendJson(res, {
    result: true,
    data: {
      is_repeat: true,
      message: `Пример answer:${message}`,
    },
  });
}

if (require.main === module) {
  runServer(handler, 9018);
}

module.exports = { handler };
