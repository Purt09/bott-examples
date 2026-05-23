'use strict';

/**
 * Отладочный обработчик: сохраняет POST и GET в post.txt и get.txt.
 *
 * Запуск: node javascript/feedback/save-in-file.js
 */

const path = require('path');
const { parsePostForm, parseQuery, sendText, runServer, fs } = require('../common');

const DIR = __dirname;

async function handler(req, res) {
  const post = await parsePostForm(req);
  const query = parseQuery(req.url);

  fs.writeFileSync(path.join(DIR, 'post.txt'), JSON.stringify(post), 'utf8');
  fs.writeFileSync(path.join(DIR, 'get.txt'), JSON.stringify(query), 'utf8');

  sendText(res, '');
}

if (require.main === module) {
  runServer(handler, 9019);
}

module.exports = { handler };
