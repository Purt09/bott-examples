"""
Обработчик формы обратной связи BOT-T (пример отклонения).

Ответ: result=false и message с телом запроса.
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common import get_post_form, wsgi_json_response


def application(environ, start_response):
    post = get_post_form(environ)
    message = json.dumps(post, ensure_ascii=False)
    return wsgi_json_response(
        start_response,
        {"result": False, "message": f"Пример answer:{message}"},
    )


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8017, application).serve_forever()
