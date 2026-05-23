"""
Вебхук «проверка товара перед выдачей» (BOT-T).

Только для типа «Уникальный товар». POST с полем product (строка склада).
Ответ: {"success": true} — строка закрепляется за заказом.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common import get_post_form, wsgi_json_response


def application(environ, start_response):
    if environ.get("REQUEST_METHOD") != "POST":
        return wsgi_json_response(
            start_response,
            {"success": False, "error": "Method not allowed"},
            "405 Method Not Allowed",
        )

    post = get_post_form(environ)
    product = post.get("product")

    if product is None or product == "":
        return wsgi_json_response(start_response, {"success": False})

    return wsgi_json_response(start_response, {"success": True})


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8015, application).serve_forever()
