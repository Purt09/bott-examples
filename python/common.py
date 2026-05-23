"""Общие утилиты для WSGI-endpoint'ов (аналог $_GET / $_POST PHP)."""

from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from typing import Any


def get_query(environ: dict) -> dict[str, str]:
    qs = environ.get("QUERY_STRING", "")
    parsed = urllib.parse.parse_qs(qs, keep_blank_values=True)
    return {k: v[0] if v else "" for k, v in parsed.items()}


def _set_nested(target: dict, keys: list[str], value: Any) -> None:
    current = target
    for key in keys[:-1]:
        if key not in current or not isinstance(current[key], dict):
            current[key] = {}
        current = current[key]
    current[keys[-1]] = value


def unflatten_brackets(flat: dict[str, Any]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in flat.items():
        parts: list[str] = []
        for part in key.replace("]", "").split("["):
            if part:
                parts.append(part)
        if not parts:
            continue
        _set_nested(result, parts, value)
    return result


def get_post_form(environ: dict) -> dict[str, Any]:
    try:
        length = int(environ.get("CONTENT_LENGTH") or 0)
    except ValueError:
        length = 0
    body = environ["wsgi.input"].read(length) if length else b""
    parsed = urllib.parse.parse_qs(
        body.decode("utf-8", errors="replace"),
        keep_blank_values=True,
    )
    flat = {k: v[0] if len(v) == 1 else v for k, v in parsed.items()}
    return unflatten_brackets(flat)


def wsgi_json_response(
    start_response,
    data: Any,
    status: str = "200 OK",
) -> list[bytes]:
    body = json.dumps(data, ensure_ascii=False).encode("utf-8")
    start_response(status, [("Content-Type", "application/json; charset=utf-8")])
    return [body]


def wsgi_text_response(start_response, text: str, status: str = "200 OK") -> list[bytes]:
    body = text.encode("utf-8")
    start_response(status, [("Content-Type", "text/plain; charset=utf-8")])
    return [body]


def post_form_urlencoded(url: str, data: dict[str, Any]) -> str | None:
    encoded = urllib.parse.urlencode(
        {k: v for k, v in data.items()},
        doseq=True,
    ).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=encoded,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return resp.read().decode("utf-8", errors="replace")
    except urllib.error.URLError:
        return None


def post_json(url: str, payload: dict[str, Any]) -> tuple[str | None, dict | None]:
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            text = resp.read().decode("utf-8", errors="replace")
            try:
                return text, json.loads(text)
            except json.JSONDecodeError:
                return text, None
    except urllib.error.URLError:
        return None, None
