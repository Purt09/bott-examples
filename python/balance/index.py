"""
Вебхук «уведомление после оплаты заказа» (BOT-T).

Начисляет покупателю баланс за каждую единицу товара в заказе: count × 100 копеек × coef,
затем зачисление через API add-balance (сумма в рублях).

Параметры URL: bot_id, token, coef.
Тело вебхука: count, botUser[id].

Пример URL в ЛК:
https://your-host/python/balance/?bot_id=1&token=BOT_TOKEN&coef=1
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common import get_post_form, get_query, post_form_urlencoded, wsgi_text_response


def application(environ, start_response):
    if environ.get("REQUEST_METHOD") != "POST":
        return wsgi_text_response(start_response, "Method not allowed", "405 Method Not Allowed")

    query = get_query(environ)
    post = get_post_form(environ)

    bot_id = query.get("bot_id")
    token = query.get("token")
    coef = query.get("coef")

    count = post.get("count")
    if count is None or count == "":
        return wsgi_text_response(start_response, "not found count")

    bot_user = post.get("botUser") or {}
    bot_user_id = bot_user.get("id")

    amount = int(int(count) * 100 * float(coef))

    url = f"https://api.bot-t.com/v1/bot/user/add-balance?token={token}"
    data = {
        "bot_id": bot_id,
        "user_id": bot_user_id,
        "sum": round(amount / 100, 2),
    }
    post_form_urlencoded(url, data)
    return wsgi_text_response(start_response, "")


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8011, application).serve_forever()
