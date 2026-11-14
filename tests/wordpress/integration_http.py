#!/usr/bin/env python3
"""Real HTTP contracts. Run inside the isolated compact-dev fixture container."""
import base64
import hashlib
import http.cookiejar
import json
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = 'http://127.0.0.1:8081'
TOKEN = 'integration-only-route-token'
FIXTURE = json.load(open('/workspace/wp-integration-fixture.json'))
JAR = http.cookiejar.CookieJar()
HTTP = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(JAR))
RUN = uuid.uuid4().hex[:12]
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZr0AAAAASUVORK5CYII=')
checks = 0


def check(condition, description, detail=None):
    global checks
    if not condition:
        raise AssertionError(description + (': ' + str(detail) if detail is not None else ''))
    checks += 1
    print('PASS ' + description)


def request(path, *, fields=None, files=None, raw=None, headers=None, auth=False, raw_response=False):
    headers = dict(headers or {})
    data = raw
    if files:
        boundary = 'wpclamav' + uuid.uuid4().hex
        parts = []
        for key, value in (fields or {}).items():
            parts.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n'.encode(), str(value).encode(), b'\r\n'])
        for key, name, content in files:
            mime = 'text/plain' if name.endswith('.txt') else 'image/png'
            parts.extend([f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode(), content, b'\r\n'])
        parts.append(f'--{boundary}--\r\n'.encode())
        data = b''.join(parts)
        headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
    elif fields is not None:
        data = urllib.parse.urlencode(fields).encode()
        headers['Content-Type'] = 'application/x-www-form-urlencoded'
    if auth:
        credentials = 'integration-admin:' + FIXTURE['application_password']
        headers['Authorization'] = 'Basic ' + base64.b64encode(credentials.encode()).decode()
    req = urllib.request.Request(BASE + path, data=data, headers=headers)
    try:
        response = HTTP.open(req, timeout=40)
    except urllib.error.HTTPError as exc:
        response = exc
    body = response.read()
    if raw_response:
        return response.status, body
    body = body.decode()
    try:
        result = json.loads(body)
    except json.JSONDecodeError:
        result = {'http_body': body[:500]}
    return response.status, result


def route(name, **kwargs):
    headers = {'X-WP-ClamAV-Test': TOKEN}
    headers.update(kwargs.pop('headers', {}))
    return request('/?rest_route=/wp-clamav-tests/v1/' + name, headers=headers, **kwargs)


def state():
    code, data = route('state')
    if code != 200:
        raise AssertionError('fixture state endpoint unavailable: ' + str(data))
    return data


def original(marker):
    return PNG + ('\n' + RUN + ':' + marker).encode()


def sha(content):
    return hashlib.sha256(content).hexdigest()


def private_and_absent(content, description):
    current = state()
    receipts = [r for r in current['receipts'] if r['sha256'] == sha(content)]
    check(bool(receipts), description + ' original has a private receipt')
    check(not any(f['sha256'] == sha(content) for f in current['public_files']), description + ' original is absent from public uploads')
    return receipts[-1]


def core_uploads():
    for name in ['bits', 'sideload', 'custom-action']:
        for status in ['CLEAN', 'DETECTED', 'HELD', 'FAILED']:
            content = original(name + '-TEST-' + status)
            filename = RUN + '-' + name + '-' + status.lower() + '.png'
            if name == 'custom-action':
                code, result = route(name, files=[('file', filename, content)], fields={})
            else:
                code, result = route(name, fields={'name': filename, 'bytes': base64.b64encode(content).decode()})
            if status == 'CLEAN':
                check(code == 200 and bool(result.get('url')), name + ' publishes qualified clean original', result)
            else:
                check(code >= 400 or bool(result.get('error')), name + ' blocks ' + status.lower())
                private_and_absent(content, name + ' ' + status.lower())
    for mode in ['multipart', 'raw']:
        for status in ['CLEAN', 'DETECTED', 'HELD', 'FAILED']:
            # PHP's minimal test image has no image editor. Plain media exercises
            # the same upload/sideload paths without a thumbnail dependency.
            content = (RUN + '-rest-' + mode + '-TEST-' + status).encode()
            filename = RUN + '-rest-' + mode + '-' + status.lower() + '.txt'
            if mode == 'multipart':
                code, result = request('/?rest_route=/wp/v2/media', files=[('file', filename, content)], fields={}, auth=True)
            else:
                code, result = request('/?rest_route=/wp/v2/media', raw=content, auth=True,
                    headers={'Content-Type': 'text/plain', 'Content-Disposition': 'attachment; filename="' + filename + '"'})
            if status == 'CLEAN':
                check(code == 201 and bool(result.get('id')), 'REST ' + mode + ' publishes clean media', result)
            else:
                check(code >= 400 and 'id' not in result, 'REST ' + mode + ' blocks ' + status.lower())
                private_and_absent(content, 'REST ' + mode + ' ' + status.lower())


def nonce(form_id, kind='submit'):
    code, result = route('nonce&form_id=' + str(form_id) + '&kind=' + kind)
    check(code == 200 and bool(result.get('nonce')), 'nonce available for fixture form')
    return result['nonce']


def submit_form(mode, contents, token=None, missing=False, *, tokens=None, native_files=None,
                extra_fields=None, headers=None, padding='', expect_mail=True):
    form_id = FIXTURE['forms'][mode]
    narrative = RUN + ' written narrative ' + mode + ' ' + uuid.uuid4().hex + padding
    fields = {'action': 'forminator_submit_form_custom-forms', 'form_id': form_id,
              'forminator_nonce': nonce(form_id), 'text-1': narrative, 'page_id': '0',
              'current_url': BASE + '/', 'referer_url': BASE + '/', 'form_type': 'custom-forms'}
    files = []
    if mode == 'ajax' and (token or tokens):
        fields['forminator-multifile-hidden'] = json.dumps({'upload-1_fixture': [{'success': True, 'access_token': t,
            'file_name': 'evidence.png', 'mime_type': 'image/png', 'file_id': 'fixture', 'scanner_warnings': []}
            for t in (tokens if tokens is not None else [token])]})
    elif not missing:
        key = 'upload-1' if mode == 'single' else 'upload-1[]'
        files = [(key, RUN + '-' + mode + '-' + str(i) + '.png', c) for i, c in enumerate(contents)]
    if native_files is not None:
        files = native_files
    fields.update(extra_fields or {})
    if fields.get('abandoned_form'):
        fields['forminator_abandonment_nonce'] = nonce(form_id, 'abandonment')
    code, result = request('/wp-admin/admin-ajax.php', fields=fields, files=files, headers=headers)
    check(code == 200 and result.get('success') is True and result.get('data', {}).get('success') is True,
          'Forminator ' + mode + ' acknowledges narrative', result)
    current = state()
    manifests = [s for s in current['submissions'] if narrative in json.dumps(s)]
    check(len(manifests) == 1, 'Forminator ' + mode + ' canonical narrative persists once')
    mails = [m for m in current['mail'] if narrative in m.get('message', '')]
    if expect_mail:
        check(bool(mails), 'Forminator ' + mode + ' narrative mail is retained independently')
    else:
        check(not mails, 'Forminator ' + mode + ' respects vendor suppression of non-final narrative mail')
    check(all(not m.get('attachments') for m in mails), 'Forminator ' + mode + ' native narrative mail has no original attachments')
    check(all('wp-content/uploads/' not in m.get('message', '') for m in mails), 'Forminator ' + mode + ' native narrative mail has no public attachment URLs')
    return manifests[0]


REAL_CLOCK = {'X-WP-ClamAV-Test': TOKEN, 'X-WP-ClamAV-Test-Clock': 'real'}


def dispatches_for(ids):
    return [d for d in state()['dispatches'] if d['id'] in ids]


def verify_held_manifest(manifest, count, reason):
    ids = manifest['receipt_ids']
    check(len(ids) == count, reason + ' preserves every received original reference')
    check(manifest['fields']['scan_receipt_ids'] == [], reason + ' commits no automatic native eligibility')
    for _ in range(2):
        route('queue', fields={}, headers=REAL_CLOCK)
    for receipt_id in ids:
        code, event = route('assess', fields={'id': receipt_id, 'submission_id': manifest['id']}, headers=REAL_CLOCK)
        check(code == 200 and event.get('status') == 'held', reason + ' remains held when automatic assessment is requested', event)
    check(dispatches_for(ids) == [], reason + ' never dispatches the scanner backend')
    receipts = {r['id']: r for r in state()['receipts']}
    check(all(receipts[r]['complete'] and not receipts[r].get('deleted_at') for r in ids), reason + ' retains complete private originals')
    check(all(not any(p['sha256'] == receipts[r]['sha256'] and (receipts[r]['size'] > 0 or RUN in p['name'])
                      for p in state()['public_files']) for r in ids), reason + ' publishes no original')


def upload_ajax(content, label):
    form_id = FIXTURE['forms']['ajax']
    code, result = request('/wp-admin/admin-ajax.php', fields={'action': 'forminator_multiple_file_upload',
        'form_id': form_id, 'element_id': 'upload-1', 'nonce': nonce(form_id), 'totalFiles': '0'},
        files=[('upload-1', RUN + '-' + label + '.png', content)], headers=REAL_CLOCK)
    check(code == 200 and result.get('success') is True, 'AJAX original is received independently of client totalFiles', result)
    token = result['data']['access_token']
    return token, token.split('.')[0]


def forminator_lifecycle():
    cases = [
        ('draft', {'save_draft': 'true'}, {}, 'draft'),
        ('abandoned', {'abandoned_form': 'true'}, {}, 'abandoned'),
        ('spam', {}, {'X-WP-ClamAV-Test-Lifecycle': 'spam'}, 'spam'),
        ('preview', {'action': 'forminator_submit_preview_form_custom-forms'}, {}, 'active'),
    ]
    for label, fields, headers, expected_status in cases:
        token, receipt_id = upload_ajax(original('lifecycle-' + label), 'lifecycle-' + label)
        manifest = submit_form('ajax', [], tokens=[token], extra_fields=fields,
            headers={**REAL_CLOCK, **headers}, expect_mail=label == 'preview')
        check(manifest['receipt_ids'] == [receipt_id], label + ' retains its authenticated AJAX original')
        check(manifest['fields']['forminator_entry_status'] == expected_status,
              label + ' follows the actual vendor entry lifecycle', manifest['fields'])
        check(bool(manifest['fields']['scan_lifecycle_holds']), label + ' records a server-side lifecycle hold')
        verify_held_manifest(manifest, 1, label + ' lifecycle')


def forminator_amplification():
    legacy_cron_before = sum(item['count'] for item in state()['scan_cron'] if item['hook'] == 'wp_clamav_scan_receipt')
    manifest = submit_form('single', [], native_files=[('upload-1[]', RUN + '-single-' + str(i) + '.png', original('single-count-' + str(i)))
        for i in range(2)], headers=REAL_CLOCK)
    verify_held_manifest(manifest, 2, 'single-field count violation')
    manifest = submit_form('twofields', [], native_files=[(field + '[]', RUN + '-' + field + '-' + str(i) + '.png', original(field + '-request-count-' + str(i)))
        for field in ['upload-1', 'upload-2'] for i in range(2)], headers=REAL_CLOCK)
    verify_held_manifest(manifest, 4, 'aggregate request count violation')
    manifest = submit_form('submission', [original('flood-' + str(i)) for i in range(10)],
        extra_fields={'totalFiles': '0', 'scanner_warnings': '[]', 'scan_receipt_ids': '[]'}, headers=REAL_CLOCK)
    verify_held_manifest(manifest, 10, 'multipart upload flood')
    manifest = submit_form('single', [b''], headers=REAL_CLOCK)
    verify_held_manifest(manifest, 1, 'zero-byte original')
    manifest = submit_form('typeblocked', [original('blocked-png')], headers=REAL_CLOCK)
    verify_held_manifest(manifest, 1, 'server-configured disallowed extension')
    manifest = submit_form('single', [original('unqualified')], headers={**REAL_CLOCK, 'X-WP-ClamAV-Test-Version': 'unqualified'})
    verify_held_manifest(manifest, 1, 'unqualified Forminator version')
    manifest = submit_form('single', [original('framing')], padding='X' * 5000, headers=REAL_CLOCK)
    verify_held_manifest(manifest, 1, 'request framing budget violation')

    uploaded = [upload_ajax(original('ajax-count-' + str(i)), 'ajax-count-' + str(i)) for i in range(4)]
    tokens, ids = [x[0] for x in uploaded], [x[1] for x in uploaded]
    route('queue', fields={}, headers=REAL_CLOCK)
    for receipt_id in ids:
        code, event = route('assess', fields={'id': receipt_id}, headers=REAL_CLOCK)
        check(code == 200 and event.get('status') == 'held', 'unbound AJAX receipt cannot request automatic native work', event)
    check(dispatches_for(ids) == [], 'AJAX uploads never dispatch before a narrative commits')
    manifest = submit_form('ajax', [], tokens=tokens, headers=REAL_CLOCK,
        extra_fields={'totalFiles': '0', 'adapter_qualified': '1', 'scan_receipt_ids': json.dumps(ids)})
    verify_held_manifest(manifest, 4, 'verified AJAX token count violation')

    valid = submit_form('ajax', [], tokens=[tokens[0]], headers=REAL_CLOCK)
    check(valid['fields']['forminator_entry_status'] == 'active' and valid['fields']['scan_lifecycle_holds'] == [],
          'accepted final entry has the qualified active lifecycle')
    check(valid['fields']['scan_receipt_ids'] == [ids[0]], 'valid server-bound AJAX subset commits its exact eligible original')
    for _ in range(5):
        code, event = route('assess', fields={'id': ids[0], 'submission_id': valid['id']}, headers=REAL_CLOCK)
        if event.get('status') == 'clean':
            break
        check(event.get('status') == 'held' and 'resource_deferred' in event.get('reason_codes', []),
              'real-clock positive control observes resource pacing before retry', event)
        delay = event.get('retry_at', 0) - time.time() + 0.05
        check(0 <= delay <= 10, 'real-clock test retries only its bounded resource-policy deadline', event)
        time.sleep(delay)
    check(len(dispatches_for([ids[0]])) == 1 and dispatches_for([ids[0]])[0]['real_clock'], 'valid bound original dispatches once through the real-clock resource policy')
    repeated = submit_form('ajax', [], tokens=[tokens[0]], headers=REAL_CLOCK)
    for _ in range(2):
        route('queue', fields={}, headers=REAL_CLOCK)
    check(len(dispatches_for([ids[0]])) == 1, 'repeated AJAX token submission cannot reset automatic scan retry state')
    check(sum(item['count'] for item in state()['scan_cron'] if item['hook'] == 'wp_clamav_dispatch') <= 1,
          'all automatic work shares one coalesced WordPress dispatch event')
    check(sum(item['count'] for item in state()['scan_cron'] if item['hook'] == 'wp_clamav_scan_receipt') == legacy_cron_before,
          'upload flood never creates per-receipt WordPress cron entries')

    for action in ['wp_clamav_retry', 'wp_clamav_full_scan', 'wp_clamav_scan_receipt']:
        code, result = request('/wp-admin/admin-post.php', fields={'action': action, 'receipt': ids[0], 'path': '/etc/passwd'}, headers=REAL_CLOCK)
        check(code >= 400, 'unauthenticated request cannot invoke ' + action)
    code, result = request('/wp-admin/admin-ajax.php', fields={'action': 'forminator_multiple_file_upload',
        'form_id': FIXTURE['forms']['ajax'], 'element_id': 'upload-1', 'nonce': nonce(FIXTURE['forms']['ajax']),
        'upload-1': '/etc/passwd', 'tmp_name': '/etc/passwd'}, headers=REAL_CLOCK)
    check(code == 200 and result.get('success') is False, 'client local path never substitutes for a genuine PHP upload')
    check(len(dispatches_for([ids[0]])) == 1, 'unauthenticated retry/path probes cause no new scanner dispatch')


def forminator_uploads():
    for mode in ['single', 'submission']:
        contents = [original(mode + '-TEST-DETECTED')]
        if mode == 'submission':
            contents.append(original(mode + '-TEST-HELD'))
        manifest = submit_form(mode, contents)
        for content in contents:
            receipt = private_and_absent(content, 'Forminator ' + mode)
            check(receipt['id'] in manifest['receipt_ids'], 'Forminator ' + mode + ' receipt is bound to its canonical narrative')
            code, event = route('assess', fields={'id': receipt['id'], 'submission_id': manifest['id']})
            check(code == 200 and event.get('status') in ['detected', 'held'], 'Forminator scan assesses privately')
            private_and_absent(content, 'Forminator ' + mode + ' after assessment')
    submit_form('single', [], missing=True)
    form_id = FIXTURE['forms']['ajax']
    content = original('ajax-TEST-DETECTED')
    code, result = request('/wp-admin/admin-ajax.php', fields={'action': 'forminator_multiple_file_upload',
        'form_id': form_id, 'element_id': 'upload-1', 'nonce': nonce(form_id), 'totalFiles': 1},
        files=[('upload-1', RUN + '-ajax.png', content)])
    check(code == 200 and result.get('success') is True and bool(result.get('data', {}).get('access_token')),
          'Forminator AJAX returns private receipt token', result)
    token = result['data']['access_token']
    receipt = private_and_absent(content, 'Forminator AJAX before submit')
    manifest = submit_form('ajax', [], token=token)
    check(receipt['id'] in manifest['receipt_ids'], 'AJAX receipt is bound to canonical narrative')
    manifest = submit_form('ajax', [], token='0' * 32 + '.' + '0' * 64)
    check(manifest['receipt_ids'] == [], 'Invalid AJAX receipt cannot bind another original')
    current = state()
    check(not any('file_path' in json.dumps(s) and '/tmp/' in json.dumps(s) for s in current['submissions']), 'Canonical submissions expose no temporary upload paths')


def administrator_downloads():
    # A completed held result is reviewable only by this explicit admin action.
    content = original('administrator-TEST-HELD-REVIEW')
    route('bits', fields={'name': RUN + '-review-held.png', 'bytes': base64.b64encode(content).decode()})
    private_and_absent(content, 'completed held administrative review')
    request('/wp-login.php', raw_response=True)
    code, page = request('/wp-login.php', fields={'log': 'integration-admin', 'pwd': 'integration-only-password',
        'testcookie': '1', 'redirect_to': BASE + '/wp-admin/'}, raw_response=True)
    check(code == 200 and b'wp-admin-bar-my-account' in page, 'administrator authenticates through actual WordPress login')
    for marker, eligible in [('bits-TEST-CLEAN', True), ('bits-TEST-DETECTED', True),
                             ('administrator-TEST-HELD-REVIEW', True), ('bits-TEST-HELD', False), ('bits-TEST-FAILED', False)]:
        expected = original(marker)
        receipt = next(r for r in state()['receipts'] if r['sha256'] == sha(expected))
        query = urllib.parse.urlencode({'page': 'wp-clamav', 'tab': 'review', 'receipt': receipt['id']})
        code, html = request('/wp-admin/tools.php?' + query, raw_response=True)
        check(code == 200, 'administrator can load the canonical review page')
        forms = re.findall(r'<form\b.*?</form>', html.decode(), re.S)
        downloads = [f for f in forms if 'value="wp_clamav_download_original"' in f]
        check(bool(downloads) == eligible, 'review UI exposes original bytes only for eligible completed result: ' + marker)
        fields = {'action': 'wp_clamav_download_original', 'receipt': receipt['id'],
                  'event_id': receipt['latest_event_id'], 'sha256': receipt['sha256'],
                  'reason': 'Integration administrative review', 'confirmed': '1'}
        code, nonce_data = route('nonce&' + urllib.parse.urlencode({'kind': 'admin_download', **fields}))
        fields['_wpnonce'] = nonce_data['nonce']
        code, body = request('/wp-admin/admin-post.php', fields=fields, raw_response=True)
        check((code == 200 and body == expected) if eligible else (code == 403 and expected not in body),
              'administrative endpoint enforces complete current disposition: ' + marker)
        if eligible:
            audit = [a for a in state()['audit'] if a.get('action') == 'administrator_download_authorized'
                     and a.get('upload_id') == receipt['id']]
            check(bool(audit) and audit[-1]['event_id'] == receipt['latest_event_id']
                  and audit[-1]['sha256'] == receipt['sha256'] and audit[-1]['actor'] == 1,
                  'administrator download records actor, reason and exact event/hash')
            invalid = dict(fields, _wpnonce='invalid')
            code, body = request('/wp-admin/admin-post.php', fields=invalid, raw_response=True)
            check(code == 403 and expected not in body, 'invalid administrator nonce never exposes bytes')
            invalid = dict(fields, reason='')
            code, body = request('/wp-admin/admin-post.php', fields=invalid, raw_response=True)
            check(code == 403 and expected not in body, 'administrator review reason is required')
            invalid = dict(fields, sha256='0' * 64)
            code, body = request('/wp-admin/admin-post.php', fields=invalid, raw_response=True)
            check(code == 403 and expected not in body, 'changed administrator hash binding never exposes bytes')
            route('assess', fields={'id': receipt['id']})
            code, body = request('/wp-admin/admin-post.php', fields=fields, raw_response=True)
            check(code == 403 and expected not in body, 'superseded administrator scan event never exposes bytes')
    tampered = original('admin-tamper-TEST-CLEAN')
    route('bits', fields={'name': RUN + '-admin-tamper.png', 'bytes': base64.b64encode(tampered).decode()})
    receipt = next(r for r in state()['receipts'] if r['sha256'] == sha(tampered))
    fields = {'action': 'wp_clamav_download_original', 'receipt': receipt['id'], 'event_id': receipt['latest_event_id'],
              'sha256': receipt['sha256'], 'reason': 'Integrity boundary fixture', 'confirmed': '1'}
    code, nonce_data = route('nonce&' + urllib.parse.urlencode({'kind': 'admin_download', **fields}))
    fields['_wpnonce'] = nonce_data['nonce']
    route('tamper', fields={'id': receipt['id']})
    code, body = request('/wp-admin/admin-post.php', fields=fields, raw_response=True)
    check(code == 403 and tampered not in body, 'tampered private original cannot reuse a current administrator scan verdict')
    JAR.clear()
    request('/wp-login.php', raw_response=True)
    code, page = request('/wp-login.php', fields={'log': 'integration-subscriber', 'pwd': 'integration-only-subscriber-password',
        'testcookie': '1', 'redirect_to': BASE + '/wp-admin/profile.php'}, raw_response=True)
    check(code == 200 and b'wp-admin-bar-my-account' in page, 'subscriber authenticates through actual WordPress login')
    code, body = request('/wp-admin/admin-post.php', fields=fields, raw_response=True)
    check(code == 403 and expected not in body, 'authenticated subscriber cannot use administrator review download')
    JAR.clear()
    code, body = request('/wp-admin/admin-post.php', fields=fields, raw_response=True)
    check(code >= 400 and expected not in body, 'anonymous request cannot use administrator review download')


if __name__ == '__main__':
    forminator_lifecycle()
    forminator_amplification()
    route('virtual-clock', fields={}, headers=REAL_CLOCK)
    core_uploads()
    forminator_uploads()
    administrator_downloads()
    print(str(checks) + ' real HTTP checks passed (WordPress7.1.4/Forminator1.58.4; fixture backend only).')
