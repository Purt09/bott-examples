"""
Вебхук «уведомление после оплаты заказа» (BOT-T).

Начисляет отчисление другому пользователю бота (не покупателю): фиксированный bot_user_id
из URL, сумма = amount заказа (копейки) × coef.

Параметры URL: bot_id, token, bot_user_id, coef.
Тело вебхука: id (заказ), amount.

Пример URL в ЛК:
https://your-host/python/bonususer/?bot_id=1&token=BOT_TOKEN&bot_user_id=42&coef=0.1
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
    bot_user_id = query.get("bot_user_id")
    coef = query.get("coef")

    amount = post.get("amount")
    order_id = post.get("id")
    if order_id is None or order_id == "":
        return wsgi_text_response(start_response, "not found order_id")

    amount = int(float(amount) * float(coef))

    url = f"https://api.bot-t.com/v1/bot/user/add-balance?token={token}"
    data = {
        "bot_id": bot_id,
        "user_id": bot_user_id,
        "sum": round(amount / 100, 2),
        "comment": f"Начисление отчисления от заказа{order_id}",
    }
    post_form_urlencoded(url, data)
    return wsgi_text_response(start_response, "")


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8012, application).serve_forever()
