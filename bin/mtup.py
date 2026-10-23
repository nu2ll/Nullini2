import asyncio
import json
import os
import sys
import time

from telethon import TelegramClient, types, utils
from telethon.sessions import StringSession


def emit(obj):
    print(json.dumps(obj, ensure_ascii=False), flush=True)


async def main():
    a = json.loads(os.environ['NL_JOB'])
    path = a['session_file']
    saved = open(path).read().strip() if os.path.exists(path) else ''
    client = TelegramClient(StringSession(saved), int(a['api_id']), a['api_hash'],
                            connection_retries=3, request_retries=3, flood_sleep_threshold=90)
    await client.start(bot_token=a['bot_token'])
    fresh = client.session.save()
    if fresh != saved:
        tmp = path + '.tmp'
        with open(tmp, 'w') as f:
            f.write(fresh)
        os.replace(tmp, path)

    real, kind = utils.resolve_id(int(a['chat']))
    peer = await client.get_input_entity(kind(real))

    last = [0.0]

    def progress(done, total):
        now = time.monotonic()
        if now - last[0] >= 3 or done >= total:
            last[0] = now
            emit({'p': done, 't': total})

    attrs = [types.DocumentAttributeFilename(a['name'])]
    if a['kind'] == 'video':
        attrs.append(types.DocumentAttributeVideo(duration=int(a.get('duration') or 0), w=int(a.get('width') or 0),
                                                  h=int(a.get('height') or 0), supports_streaming=True))
    elif a['kind'] == 'audio':
        attrs.append(types.DocumentAttributeAudio(duration=int(a.get('duration') or 0),
                                                  title=a.get('title') or None, performer=a.get('performer') or None))

    msg = await client.send_file(
        peer, a['file'],
        caption=a.get('caption') or '', parse_mode='html',
        reply_to=a.get('reply_to') or None,
        attributes=attrs, thumb=a.get('thumb') or None,
        mime_type=a.get('mime') or None,
        supports_streaming=a['kind'] == 'video',
        force_document=a['kind'] == 'document',
        progress_callback=progress, part_size_kb=512,
    )
    emit({'ok': True, 'msg_id': msg.id})
    await client.disconnect()


try:
    asyncio.run(main())
except Exception as e:
    emit({'ok': False, 'error': type(e).__name__ + ': ' + str(e)[:300]})
    sys.exit(1)
