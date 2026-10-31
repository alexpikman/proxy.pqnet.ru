"""Canonical 3x-ui client API routes used for capability checks."""

EXPECTED_ENDPOINTS = {
    "clients_list": "/panel/api/clients/list",
    "inbounds_list": "/panel/api/inbounds/list",
    "client_get": "/panel/api/clients/get/{email}",
    "client_create": "/panel/api/clients",
    "client_update": "/panel/api/clients/update/{email}",
    "client_delete": "/panel/api/clients/del/{email}",
    "client_attach": "/panel/api/clients/{email}/attach",
}
