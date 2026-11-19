#!/usr/bin/env python3
"""Build Webasyst MO and browser catalogs from the checked-in PO sources."""
import argparse
import ast
import json
import struct
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read_po(path):
    catalog = {}
    entry = {}
    field = None

    def flush():
        if entry:
            if 'msgid' not in entry or 'msgstr' not in entry:
                raise ValueError(f'Incomplete entry in {path}')
            if entry['msgid'] in catalog:
                raise ValueError(f'Duplicate message in {path}: {entry["msgid"]}')
            catalog[entry['msgid']] = entry['msgstr']
            entry.clear()

    for raw in path.read_text().splitlines() + ['']:
        line = raw.strip()
        if not line:
            flush()
            field = None
        elif line.startswith('#'):
            continue
        elif line.startswith(('msgid ', 'msgstr ')):
            field, text = line.split(' ', 1)
            entry[field] = ast.literal_eval(text)
        elif line.startswith('"') and field:
            entry[field] += ast.literal_eval(line)
        else:
            raise ValueError(f'Unsupported PO syntax in {path}: {line}')
    if '' not in catalog or any(not value for value in catalog.values()):
        raise ValueError(f'Missing metadata or translation in {path}')
    return catalog


def mo_bytes(catalog):
    items = sorted((key.encode(), value.encode()) for key, value in catalog.items())
    count = len(items)
    ids_offset = 28 + count * 16
    values_offset = ids_offset + sum(len(key) + 1 for key, _ in items)
    id_table, value_table = [], []
    ids, values = b'', b''
    for key, value in items:
        id_table.extend([len(key), ids_offset + len(ids)])
        value_table.extend([len(value), values_offset + len(values)])
        ids += key + b'\0'
        values += value + b'\0'
    return (struct.pack('<7I', 0x950412DE, 0, count, 28, 28 + count * 8, 0, 0)
            + struct.pack(f'<{count * 2}I', *id_table)
            + struct.pack(f'<{count * 2}I', *value_table) + ids + values)


def build(check=False):
    for locale in ('en_US', 'ru_RU'):
        source = ROOT / 'locale' / locale / 'LC_MESSAGES/mps_avito.po'
        catalog = read_po(source)
        messages = {key: value for key, value in catalog.items() if key}
        outputs = {source.with_suffix('.mo'): mo_bytes(catalog)}
        for path, data in outputs.items():
            if check:
                if not path.exists() or path.read_bytes() != data:
                    raise ValueError(f'Outdated generated catalog: {path}')
            else:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(data)
        print(f'{locale}: {len(messages)} translations OK')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true')
    build(parser.parse_args().check)
