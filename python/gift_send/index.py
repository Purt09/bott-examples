"""
Вебхук «уведомление после оплаты заказа» (BOT-T).

Отправляет покупателю Telegram-подарок через BOT-T API (method sendGift).
chat_id подставляется из заказа; gift_id — из каталога Telegram (getAvailableGifts).
Повторный вебхук с тем же id заказа не дублирует отправку (файл sent_{id}.lock).

Параметры URL: bot_id, token, gift_id.
Тело вебхука: id (заказ), status (обрабатывается только status=1 — оплачен).

Пример URL в ЛК:
https://your-host/python/gift_send/?bot_id=1&token=BOT_TOKEN&gift_id=GIFT_ID
"""

import os
import sys
from datetime import datetime, timezone
from urllib.parse import quote

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common import get_post_form, get_query, post_json, wsgi_json_response

_DIR = os.path.dirname(os.path.abspath(__file__))


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
    gift_id = query.get("gift_id")

    if not bot_id or not token or not gift_id:
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "Required query: bot_id, token, gift_id"},
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

    sent_marker = os.path.join(_DIR, f"sent_{order_id}.lock")
    if os.path.isfile(sent_marker):
        return wsgi_json_response(start_response, {"ok": True, "skipped": True, "reason": "already_sent"})

    url = f"https://api.bot-t.com/v1/shop/order/send-request?token={quote(token, safe='')}"
    payload = {
        "bot_id": int(bot_id),
        "order_id": order_id,
        "method": "sendGift",
        "params": {"gift_id": str(gift_id)},
    }

    _text, response = post_json(url, payload)
    if _text is None:
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": "BOT-T API request failed"},
            "502 Bad Gateway",
        )

    if not isinstance(response, dict) or not response.get("result"):
        message = response.get("message") if isinstance(response, dict) else "Invalid BOT-T response"
        return wsgi_json_response(
            start_response,
            {"ok": False, "error": message},
            "502 Bad Gateway",
        )

    with open(sent_marker, "w", encoding="utf-8") as f:
        f.write(datetime.now(timezone.utc).isoformat())

    return wsgi_json_response(start_response, {"ok": True, "order_id": order_id})


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8014, application).serve_forever()
