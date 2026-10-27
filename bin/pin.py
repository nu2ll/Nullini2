import json
import re
import sys


def urls(value, out):
    if isinstance(value, str):
        if value.startswith("http"):
            out.append(value)
    elif isinstance(value, dict):
        for item in value.values():
            urls(item, out)
    elif isinstance(value, list):
        for item in value:
            urls(item, out)


def link(value):
    if isinstance(value, str):
        return value
    if isinstance(value, dict):
        return value.get("url")
    return None


def main():
    try:
        from pinterest_downloader import Pinterest

        res = Pinterest().get_pin(sys.argv[1])
        pin = res.get("pin") or res.get("data") or res
        kind = pin.get("media_type") or pin.get("type")
        found = []
        urls(pin, found)
        gif = pin.get("gif_url") or next((u for u in found if re.search(r"\.gif(\?|$)", u, re.I)), None)
        images = []
        pics = pin.get("images") if isinstance(pin.get("images"), dict) else {}
        for key in ("orig", "736x", "474x"):
            u = link(pics.get(key))
            if u and u not in images:
                images.append(u)
        if not images and pin.get("default_image"):
            images.append(link(pin.get("default_image")))
        print(json.dumps({"ok": bool(res.get("ok", True)) and bool(pin), "type": kind, "gif": gif, "images": images}))
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)[:200]}))


main()
