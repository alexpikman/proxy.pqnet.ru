from __future__ import annotations
import base64, os, urllib.parse
from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
_AAD=b"PQService:3x-ui:api-token:v1"
def _encryption_key() -> bytes:
    encoded=os.getenv("PQ_XUI_ENCRYPTION_KEY","").strip()
    if not encoded: raise RuntimeError("Не задан PQ_XUI_ENCRYPTION_KEY в /etc/pqservice/pqservice.env.")
    try: key=base64.urlsafe_b64decode(encoded.encode("ascii"))
    except (ValueError,UnicodeEncodeError) as exc: raise RuntimeError("PQ_XUI_ENCRYPTION_KEY должен быть URL-safe Base64.") from exc
    if len(key)!=32: raise RuntimeError("PQ_XUI_ENCRYPTION_KEY должен декодироваться ровно в 32 байта.")
    return key
def encrypt_token(token: str) -> str:
    if not token or not token.strip(): raise ValueError("API Token не может быть пустым.")
    nonce=os.urandom(12)
    encrypted=AESGCM(_encryption_key()).encrypt(nonce,token.encode("utf-8"),_AAD)
    return "v1:"+base64.urlsafe_b64encode(nonce+encrypted).decode("ascii")
def decrypt_token(value: str) -> str:
    if not value or not value.startswith("v1:"): raise RuntimeError("Формат зашифрованного API Token не поддерживается.")
    try:
        raw=base64.urlsafe_b64decode(value[3:].encode("ascii"))
        if len(raw)<29: raise ValueError("ciphertext too short")
        return AESGCM(_encryption_key()).decrypt(raw[:12],raw[12:],_AAD).decode("utf-8")
    except (ValueError,UnicodeEncodeError,InvalidTag) as exc:
        raise RuntimeError("Не удалось расшифровать API Token. Проверьте постоянство PQ_XUI_ENCRYPTION_KEY.") from exc
def validate_base_url(value: str) -> str:
    url=value.strip().rstrip("/")
    parsed=urllib.parse.urlsplit(url)
    if parsed.scheme not in {"http","https"} or not parsed.netloc: raise ValueError("Адрес панели должен быть полным HTTP(S) URL.")
    if parsed.username or parsed.password or parsed.query or parsed.fragment: raise ValueError("Не добавляйте credentials, query или fragment в URL панели.")
    if parsed.scheme!="https" and parsed.hostname not in {"localhost","127.0.0.1","::1"}: raise ValueError("Для удалённых панелей разрешён только HTTPS.")
    return url
