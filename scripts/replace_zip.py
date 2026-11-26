#!/usr/bin/env python3
"""出品済みアプリの配布zipを差し替える（台帳 apps.json と kapp_data/files/<file>）。

使い方: python3 scripts/replace_zip.py <app id> <新しいzip>  [--dry-run]

- サーバー側のファイル名（file）は変えない。変えると購入者のダウンロードが切れる。
- 差し替え前の台帳と zip を outputs/replace_<id>_<日時>/ に退避する。
- 台帳は apps.json.new に送って大きさを確かめてから名前を付け替える。
- 認証情報は aixec/.env の FTP_HOST / FTP_USER / FTP_PASS（表示しない）。
"""
import ftplib, io, json, os, sys, time, datetime, zipfile

REMOTE = "/web/kappstore_exbridge_jp/kapp_data"
HERE = os.path.dirname(os.path.abspath(__file__))


def load_env(path="/home/kojima/work/aixec/.env"):
    env = {}
    for line in open(path, encoding="utf-8"):
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env


def get(ftp, path):
    buf = io.BytesIO()
    ftp.retrbinary("RETR " + path, buf.write)
    return buf.getvalue()


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    dry = "--dry-run" in sys.argv
    if len(args) != 2:
        sys.exit(__doc__)
    app_id, zpath = args
    new = open(zpath, "rb").read()
    zipfile.ZipFile(io.BytesIO(new)).testzip()

    e = load_env()
    ftp = ftplib.FTP(e["FTP_HOST"], timeout=60)
    ftp.login(e["FTP_USER"], e["FTP_PASS"])

    raw = get(ftp, REMOTE + "/apps.json")
    ledger = json.loads(raw)
    apps = ledger["apps"] if isinstance(ledger, dict) else ledger
    n_before = len(apps)
    rec = next((a for a in apps if a.get("id") == app_id), None)
    if not rec:
        sys.exit("台帳にありません: " + app_id)
    old = get(ftp, REMOTE + "/files/" + rec["file"])

    stamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    bdir = os.path.join(HERE, "..", "outputs", f"replace_{app_id}_{stamp}")
    os.makedirs(bdir, exist_ok=True)
    open(os.path.join(bdir, "apps.json"), "wb").write(raw)
    open(os.path.join(bdir, rec["filename"]), "wb").write(old)

    print(f"{rec['name']}\n  旧: {rec['filename']} {len(old)}B（台帳 {rec['filesize']}B）\n  新: {os.path.basename(zpath)} {len(new)}B")
    print("  退避:", os.path.relpath(bdir, os.path.join(HERE, "..")))
    if dry:
        return

    ftp.storbinary("STOR " + REMOTE + "/files/" + rec["file"], io.BytesIO(new))
    if ftp.size(REMOTE + "/files/" + rec["file"]) != len(new):
        sys.exit("zip の大きさが合いません。退避した旧zipを戻してください")

    rec["filename"] = os.path.basename(zpath)
    rec["filesize"] = len(new)
    rec["updated_at"] = int(time.time())
    out = json.dumps(ledger, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    ftp.storbinary("STOR " + REMOTE + "/apps.json.new", io.BytesIO(out))
    if ftp.size(REMOTE + "/apps.json.new") != len(out):
        sys.exit("apps.json.new の大きさが合いません（本番の apps.json はそのまま）")
    ftp.rename(REMOTE + "/apps.json.new", REMOTE + "/apps.json")

    chk = json.loads(get(ftp, REMOTE + "/apps.json"))
    chk_apps = chk["apps"] if isinstance(chk, dict) else chk
    r2 = next(a for a in chk_apps if a.get("id") == app_id)
    print(f"  台帳: {n_before} → {len(chk_apps)} 件 / filename={r2['filename']} filesize={r2['filesize']}")
    ftp.quit()


if __name__ == "__main__":
    main()
