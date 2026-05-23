"""
Отладочный обработчик: сохраняет POST и GET в post.txt и get.txt в каталоге скрипта.
"""

import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common import get_post_form, get_query, wsgi_text_response

_DIR = os.path.dirname(os.path.abspath(__file__))


def application(environ, start_response):
    post = get_post_form(environ)
    query = get_query(environ)

    with open(os.path.join(_DIR, "post.txt"), "w", encoding="utf-8") as f:
        f.write(json.dumps(post, ensure_ascii=False))
    with open(os.path.join(_DIR, "get.txt"), "w", encoding="utf-8") as f:
        f.write(json.dumps(query, ensure_ascii=False))

    return wsgi_text_response(start_response, "")


if __name__ == "__main__":
    from wsgiref.simple_server import make_server

    make_server("", 8019, application).serve_forever()
