"""Set up with the server's address and an API token from its console."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

import voluptuous as vol

from homeassistant.config_entries import ConfigFlow, ConfigFlowResult
from homeassistant.const import CONF_URL, CONF_VERIFY_SSL
from homeassistant.helpers.aiohttp_client import async_get_clientsession

from .api import KioskyShepherdApi, KioskyShepherdAuthError, KioskyShepherdError
from .const import CONF_API_TOKEN, DOMAIN


class KioskyShepherdConfigFlow(ConfigFlow, domain=DOMAIN):
    """Config flow: URL + API token, checked against /api/v1/admin/session."""

    VERSION = 1

    async def _check(self, url: str, token: str, verify_ssl: bool) -> tuple[dict[str, Any] | None, str | None]:
        api = KioskyShepherdApi(async_get_clientsession(self.hass, verify_ssl=verify_ssl), url, token)
        try:
            return await api.whoami(), None
        except KioskyShepherdAuthError:
            return None, "invalid_auth"
        except KioskyShepherdError:
            return None, "cannot_connect"

    async def async_step_user(self, user_input: dict[str, Any] | None = None) -> ConfigFlowResult:
        """Ask for the server and token."""
        errors: dict[str, str] = {}
        if user_input is not None:
            url = user_input[CONF_URL].strip().rstrip("/")
            if not url.startswith(("https://", "http://")):
                url = "https://" + url
            info, error = await self._check(url, user_input[CONF_API_TOKEN].strip(), user_input[CONF_VERIFY_SSL])
            if error:
                errors["base"] = error
            else:
                await self.async_set_unique_id(url.lower())
                self._abort_if_unique_id_configured()
                user = (info or {}).get("user", {})
                return self.async_create_entry(
                    title=url.split("://", 1)[-1],
                    data={CONF_URL: url, CONF_API_TOKEN: user_input[CONF_API_TOKEN].strip(),
                          CONF_VERIFY_SSL: user_input[CONF_VERIFY_SSL]},
                    description_placeholders={"role": user.get("role", "?")},
                )
        return self.async_show_form(
            step_id="user",
            data_schema=vol.Schema(
                {
                    vol.Required(CONF_URL, default=(user_input or {}).get(CONF_URL, "https://")): str,
                    vol.Required(CONF_API_TOKEN): str,
                    vol.Required(CONF_VERIFY_SSL, default=True): bool,
                }
            ),
            errors=errors,
        )

    async def async_step_reauth(self, entry_data: Mapping[str, Any]) -> ConfigFlowResult:
        """The token stopped working (revoked or expired)."""
        return await self.async_step_reauth_confirm()

    async def async_step_reauth_confirm(self, user_input: dict[str, Any] | None = None) -> ConfigFlowResult:
        """Ask for a new token."""
        entry = self._get_reauth_entry()
        errors: dict[str, str] = {}
        if user_input is not None:
            token = user_input[CONF_API_TOKEN].strip()
            _, error = await self._check(entry.data[CONF_URL], token, entry.data.get(CONF_VERIFY_SSL, True))
            if error:
                errors["base"] = error
            else:
                return self.async_update_reload_and_abort(entry, data_updates={CONF_API_TOKEN: token})
        return self.async_show_form(
            step_id="reauth_confirm",
            data_schema=vol.Schema({vol.Required(CONF_API_TOKEN): str}),
            description_placeholders={"url": entry.data[CONF_URL]},
            errors=errors,
        )
