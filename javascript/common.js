'use strict';

const http = require('http');
const https = require('https');
const { URL, URLSearchParams } = require('url');
const fs = require('fs');
const path = require('path');

function unflattenBrackets(flat) {
  const result = {};
  for (const [key, value] of Object.entries(flat)) {
    const parts = key.replace(/\]/g, '').split('[').filter(Boolean);
    let current = result;
    for (let i = 0; i < parts.length - 1; i++) {
      if (!current[parts[i]] || typeof current[parts[i]] !== 'object') {
        current[parts[i]] = {};
      }
      current = current[parts[i]];
    }
    current[parts[parts.length - 1]] = value;
  }
  return result;
}

function parseQuery(url) {
  const params = new URL(url, 'http://localhost').searchParams;
  const out = {};
  for (const [k, v] of params.entries()) {
    out[k] = v;
  }
  return out;
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

async function parsePostForm(req) {
  const raw = await readBody(req);
  const params = new URLSearchParams(raw);
  const flat = {};
  for (const [k, v] of params.entries()) {
    flat[k] = v;
  }
  return unflattenBrackets(flat);
}

function sendJson(res, data, statusCode = 200) {
  res.writeHead(statusCode, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(data));
}

function sendText(res, text, statusCode = 200) {
  res.writeHead(statusCode, { 'Content-Type': 'text/plain; charset=utf-8' });
  res.end(text);
}

function httpRequest(urlString, options, body) {
  return new Promise((resolve, reject) => {
    const url = new URL(urlString);
    const lib = url.protocol === 'https:' ? https : http;
    const req = lib.request(
      {
        hostname: url.hostname,
        port: url.port || (url.protocol === 'https:' ? 443 : 80),
        path: url.pathname + url.search,
        method: options.method || 'GET',
        headers: options.headers || {},
        timeout: 30000,
      },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          resolve({ statusCode: res.statusCode, text });
        });
      },
    );
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

async function postFormUrlencoded(urlString, data) {
  const body = new URLSearchParams(
    Object.entries(data).map(([k, v]) => [k, String(v)]),
  ).toString();
  const { text } = await httpRequest(urlString, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Content-Length': Buffer.byteLength(body),
    },
  }, body);
  return text;
}

async function postJson(urlString, payload) {
  const body = JSON.stringify(payload);
  const { text } = await httpRequest(urlString, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(body),
    },
  }, body);
  try {
    return { text, json: JSON.parse(text) };
  } catch {
    return { text, json: null };
  }
}

function runServer(handler, port = 8000) {
  const server = http.createServer((req, res) => {
    Promise.resolve(handler(req, res)).catch((err) => {
      console.error(err);
      sendJson(res, { ok: false, error: 'Internal error' }, 500);
    });
  });
  server.listen(port, () => {
    console.log(`Listening on http://127.0.0.1:${port}`);
  });
}

module.exports = {
  unflattenBrackets,
  parseQuery,
  parsePostForm,
  sendJson,
  sendText,
  postFormUrlencoded,
  postJson,
  runServer,
  fs,
  path,
};
