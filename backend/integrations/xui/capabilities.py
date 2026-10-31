"""3x-ui API capability declarations.

The adapter deliberately reports only endpoints verified by the official API
reference. Version-specific write payloads remain disabled until the installed
panel version and its OpenAPI schema have been confirmed.
"""

CAPABILITIES = {
    "clients_list": {"method": "GET", "path": "/panel/api/clients/list", "verified": True},
    "inbounds_list": {"method": "GET", "path": "/panel/api/inbounds/list", "verified": True},
    "client_get": {"method": "GET", "path": "/panel/api/clients/get/{email}", "verified": True},
    "client_create": {"method": "POST", "path": "/panel/api/clients", "verified": True},
    "client_update": {"method": "POST", "path": "/panel/api/clients/update/{email}", "verified": True},
    "client_delete": {"method": "POST", "path": "/panel/api/clients/del/{email}", "verified": True},
    "client_attach": {"method": "POST", "path": "/panel/api/clients/{email}/attach", "verified": True},
}
