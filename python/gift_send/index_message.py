"""
Вебхук «Сообщение — API» (BOT-T) — sendGift.

Параметры URL: bot_id, token, gift_id, admin_id (необязательно).
Тело: JSON с user_id, telegram_id, message_id.

Пример URL:
https://your-host/python/gift_send/index_message.py?bot_id=1&token=BOT_TOKEN&gift_id=GIFT_ID
"""

import json
import os
import re
import sys
from datetime import datetime, timezone
from urllib.parse import quote

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common import get_query, post_form_urlencoded, post_json, wsgi_json_response

_DIR = os.path.dirname(os.path.abspath(__file__))


def _parse_admin_id(query: dict) -> str | None:
    raw = query.get("admin_id")
    if not raw or not re.match(r"^-?\d+$", str(raw)):
        return None
    return str(raw)


def _get_post_json(environ: dict) -> dict | None:
    try:
        length = int(environ.get("CONTENT_LENGTH") or 0)
    except ValueError:
        length = 0
    body = environ["wsgi.input"].read(length) if length else b""
    try:
        data = json.loads(body.decode("utf-8", errors="replace") or "{}")
    except json.JSONDecodeError:
        return None
    return data if isinstance(data, dict) else None


def _notify_admin_pm(token: str, telegram_id: str, text: str) -> None:
    url = f"https://api.telegram.org/bot{token}/sendMessage"
    post_form_urlencoded(url, {"chat_id": telegram_id, "text": text})


def _admin_notify_user(
    admin_id: str | None,
    token: str,
    user_id: int,
    telegram_id: int | None,
    gift_id: str,
    success: bool,
    reason: str = "",
) -> None:
    if admin_id is None:
        return
    tg_line = f"\nTelegram: {telegram_id}" if telegram_id is not None else ""
    if success:
        text = f"Подарок отправлен пользователю.\nПользователь бота: #{user_id}{tg_line}\nПодарок: {gift_id}"
    else:
        text = (
            f"Не удалось отправить подарок.\nПользователь бота: #{user_id}{tg_line}\n"
            f"Подарок: {gift_id}\nПричина: {reason}"
        )
    _notify_admin_pm(token, admin_id, text)


def application(environ, start_response):
    if environ.get("REQUEST_METHOD") != "POST":
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Method not allowed"},
            "405 Method Not Allowed",
        )

    query = get_query(environ)
    bot_id = query.get("bot_id")
    token = query.get("token")
    gift_id = query.get("gift_id")
    admin_id = _parse_admin_id(query)

    if not bot_id or not token or not gift_id:
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Required query: bot_id, token, gift_id"},
            "400 Bad Request",
        )

    body = _get_post_json(environ)
    if body is None:
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Invalid JSON body"},
            "400 Bad Request",
        )

    user_id_raw = body.get("user_id")
    message_id_raw = body.get("message_id")
    telegram_id_raw = body.get("telegram_id")

    if user_id_raw is None or user_id_raw == "" or not re.match(r"^\d+$", str(user_id_raw)):
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Missing or invalid user_id in body"},
            "400 Bad Request",
        )
    if message_id_raw is None or message_id_raw == "" or not re.match(r"^\d+$", str(message_id_raw)):
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Missing or invalid message_id in body"},
            "400 Bad Request",
        )

    user_id = int(user_id_raw)
    message_id = int(message_id_raw)
    telegram_id = (
        int(telegram_id_raw)
        if telegram_id_raw is not None and telegram_id_raw != "" and re.match(r"^-?\d+$", str(telegram_id_raw))
        else None
    )

    sent_marker = os.path.join(_DIR, f"sent_msg_{message_id}_{user_id}.lock")
    if os.path.isfile(sent_marker):
        return wsgi_json_response(start_response, {"ok": True, "skipped": True, "reason": "already_sent"})

    url = f"https://api.bot-t.com/v1/bot/user/send-request?token={quote(token, safe='')}"
    payload = {
        "bot_id": int(bot_id),
        "user_id": user_id,
        "method": "sendGift",
        "params": {"gift_id": str(gift_id)},
    }

    _text, response = post_json(url, payload)
    if _text is None:
        _admin_notify_user(admin_id, token, user_id, telegram_id, str(gift_id), False, "BOT-T API request failed")
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "BOT-T API request failed"},
            "502 Bad Gateway",
        )

    if not isinstance(response, dict) or not response.get("result"):
        message = response.get("message") if isinstance(response, dict) else "BOT-T API error"
        _admin_notify_user(admin_id, token, user_id, telegram_id, str(gift_id), False, message)
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": message},
            "502 Bad Gateway",
        )

    with open(sent_marker, "w", encoding="utf-8") as f:
        f.write(datetime.now(timezone.utc).isoformat())

    _admin_notify_user(admin_id, token, user_id, telegram_id, str(gift_id), True)
    return wsgi_json_response(start_response, {"ok": True, "user_id": user_id, "message_id": message_id})


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8015, application).serve_forever()
