"""
Вебхук «уведомление после оплаты заказа» (BOT-T) — transferGift.

Параметры URL: bot_id, token, owned_gift_id, business_connection_id;
admin_id и star_count — необязательно.

Пример URL:
https://your-host/python/gift_send/index_transfer.py?bot_id=1&token=BOT_TOKEN&owned_gift_id=ID&business_connection_id=BC_ID
"""

import os
import re
import sys
from datetime import datetime, timezone
from urllib.parse import quote

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common import get_post_form, get_query, post_form_urlencoded, post_json, wsgi_json_response

_DIR = os.path.dirname(os.path.abspath(__file__))


def _parse_admin_id(query: dict) -> str | None:
    raw = query.get("admin_id")
    if not raw or not re.match(r"^-?\d+$", str(raw)):
        return None
    return str(raw)


def _parse_star_count(query: dict) -> int | None:
    raw = query.get("star_count")
    if not raw or not re.match(r"^\d+$", str(raw)):
        return None
    return int(raw)


def _build_transfer_params(
    business_connection_id: str,
    owned_gift_id: str,
    new_owner_chat_id: int,
    star_count: int | None,
) -> dict:
    params = {
        "business_connection_id": business_connection_id,
        "owned_gift_id": owned_gift_id,
        "new_owner_chat_id": new_owner_chat_id,
    }
    if star_count is not None and star_count > 0:
        params["star_count"] = star_count
    return params


def _notify_admin_pm(token: str, telegram_id: str, text: str) -> None:
    url = f"https://api.telegram.org/bot{token}/sendMessage"
    post_form_urlencoded(url, {"chat_id": telegram_id, "text": text})


def _admin_notify_order(
    admin_id: str | None,
    token: str,
    order_id: int,
    owned_gift_id: str,
    success: bool,
    reason: str = "",
) -> None:
    if admin_id is None:
        return
    if success:
        text = f"Коллекционный подарок передан покупателю.\nЗаказ: #{order_id}\nowned_gift_id: {owned_gift_id}"
    else:
        text = (
            f"Не удалось передать коллекционный подарок.\nЗаказ: #{order_id}\n"
            f"owned_gift_id: {owned_gift_id}\nПричина: {reason}"
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
    post = get_post_form(environ)

    bot_id = query.get("bot_id")
    token = query.get("token")
    owned_gift_id = query.get("owned_gift_id")
    business_connection_id = query.get("business_connection_id")
    admin_id = _parse_admin_id(query)
    star_count = _parse_star_count(query)

    if not bot_id or not token or not owned_gift_id or not business_connection_id:
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Required query: bot_id, token, owned_gift_id, business_connection_id"},
            "400 Bad Request",
        )

    order_id = post.get("id")
    if order_id is None or order_id == "":
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Missing order id in webhook"},
            "400 Bad Request",
        )

    order_id = int(order_id)
    status = int(post.get("status", -1))

    if status != 1:
        return wsgi_json_response(start_response, {"ok": True, "skipped": True, "reason": "status_not_paid"})

    bot_user = post.get("botUser") if isinstance(post.get("botUser"), dict) else {}
    user = bot_user.get("user") if isinstance(bot_user.get("user"), dict) else {}
    telegram_id_raw = user.get("telegram_id")
    if telegram_id_raw is None or telegram_id_raw == "" or not re.match(r"^-?\d+$", str(telegram_id_raw)):
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Missing botUser[user][telegram_id] in webhook"},
            "400 Bad Request",
        )

    new_owner_chat_id = int(telegram_id_raw)

    sent_marker = os.path.join(_DIR, f"sent_transfer_{order_id}.lock")
    if os.path.isfile(sent_marker):
        return wsgi_json_response(start_response, {"ok": True, "skipped": True, "reason": "already_sent"})

    url = f"https://api.bot-t.com/v1/shop/order/send-request?token={quote(token, safe='')}"
    payload = {
        "bot_id": int(bot_id),
        "order_id": order_id,
        "method": "transferGift",
        "params": _build_transfer_params(
            str(business_connection_id),
            str(owned_gift_id),
            new_owner_chat_id,
            star_count,
        ),
    }

    _text, response = post_json(url, payload)
    if _text is None:
        _admin_notify_order(admin_id, token, order_id, str(owned_gift_id), False, "BOT-T API request failed")
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "BOT-T API request failed"},
            "502 Bad Gateway",
        )

    if not isinstance(response, dict) or not response.get("result"):
        message = response.get("message") if isinstance(response, dict) else "BOT-T API error"
        _admin_notify_order(admin_id, token, order_id, str(owned_gift_id), False, message)
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": message},
            "502 Bad Gateway",
        )

    with open(sent_marker, "w", encoding="utf-8") as f:
        f.write(datetime.now(timezone.utc).isoformat())

    _admin_notify_order(admin_id, token, order_id, str(owned_gift_id), True)
    return wsgi_json_response(start_response, {"ok": True, "order_id": order_id})


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8016, application).serve_forever()
