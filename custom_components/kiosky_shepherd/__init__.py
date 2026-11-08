"""Kiosky Shepherd: managed Kiosky wall tablets as Home Assistant devices.

Each tablet becomes a device with online/battery/page sensors and command buttons.
The services kiosky_shepherd.send_command, .show_message and .open_page target
tablets by their Home Assistant device, e.g. show a message when the doorbell rings.
"""

from __future__ import annotations

from typing import Any

import voluptuous as vol

from homeassistant.const import CONF_URL, CONF_VERIFY_SSL, Platform
from homeassistant.core import HomeAssistant, ServiceCall
from homeassistant.exceptions import HomeAssistantError, ServiceValidationError
from homeassistant.helpers import config_validation as cv, device_registry as dr
from homeassistant.helpers.aiohttp_client import async_get_clientsession
from homeassistant.helpers.typing import ConfigType

from .api import KioskyShepherdApi, KioskyShepherdError
from .const import CONF_API_TOKEN, DOMAIN
from .coordinator import KioskyShepherdConfigEntry, KioskyShepherdCoordinator

PLATFORMS = [Platform.BINARY_SENSOR, Platform.BUTTON, Platform.SENSOR]
CONFIG_SCHEMA = cv.config_entry_only_config_schema(DOMAIN)

ATTR_DEVICE_ID = "device_id"
SEND_COMMAND_SCHEMA = vol.Schema(
    {
        vol.Required(ATTR_DEVICE_ID): vol.All(cv.ensure_list, [cv.string]),
        vol.Required("command"): cv.string,
        vol.Optional("params", default={}): dict,
    }
)
SHOW_MESSAGE_SCHEMA = vol.Schema(
    {
        vol.Required(ATTR_DEVICE_ID): vol.All(cv.ensure_list, [cv.string]),
        vol.Required("message"): vol.All(cv.string, vol.Length(min=1, max=500)),
        vol.Optional("seconds", default=30): vol.All(vol.Coerce(int), vol.Range(min=1, max=3600)),
    }
)
OPEN_PAGE_SCHEMA = vol.Schema(
    {
        vol.Required(ATTR_DEVICE_ID): vol.All(cv.ensure_list, [cv.string]),
        vol.Required("url"): cv.url,
    }
)


async def async_setup(hass: HomeAssistant, config: ConfigType) -> bool:
    """Register the services (once, for every configured server)."""

    async def _send(call: ServiceCall, command: str, params: dict[str, Any]) -> None:
        targets = _resolve(hass, call.data[ATTR_DEVICE_ID])
        errors = []
        for coordinator, server_id, name in targets:
            try:
                await coordinator.api.send_command(server_id, command, params)
            except KioskyShepherdError as err:
                errors.append(f"{name}: {err}")
        if errors:
            raise HomeAssistantError("Kiosky Shepherd: " + "; ".join(errors))

    async def send_command(call: ServiceCall) -> None:
        await _send(call, call.data["command"], call.data["params"])

    async def show_message(call: ServiceCall) -> None:
        await _send(call, "show_message", {"text": call.data["message"], "seconds": call.data["seconds"]})

    async def open_page(call: ServiceCall) -> None:
        await _send(call, "navigate", {"url": call.data["url"]})

    hass.services.async_register(DOMAIN, "send_command", send_command, schema=SEND_COMMAND_SCHEMA)
    hass.services.async_register(DOMAIN, "show_message", show_message, schema=SHOW_MESSAGE_SCHEMA)
    hass.services.async_register(DOMAIN, "open_page", open_page, schema=OPEN_PAGE_SCHEMA)
    return True


def _resolve(hass: HomeAssistant, device_ids: list[str]) -> list[tuple[KioskyShepherdCoordinator, int, str]]:
    """Home Assistant device ids → (coordinator, server device id, name)."""
    registry = dr.async_get(hass)
    out = []
    for device_id in device_ids:
        device = registry.async_get(device_id)
        uid = next((i[1] for i in device.identifiers if i[0] == DOMAIN), None) if device else None
        if device is None or uid is None:
            raise ServiceValidationError(f"{device_id} is not a Kiosky Shepherd tablet.")
        for entry_id in device.config_entries:
            entry = hass.config_entries.async_get_entry(entry_id)
            if entry is None or entry.domain != DOMAIN or not hasattr(entry, "runtime_data"):
                continue
            data = entry.runtime_data.data.get(uid)
            if data is not None:
                out.append((entry.runtime_data, int(data["id"]), device.name_by_user or device.name or uid))
                break
        else:
            raise ServiceValidationError(f"{device.name or uid} is not currently managed (retired, or out of the token's reach).")
    return out


async def async_setup_entry(hass: HomeAssistant, entry: KioskyShepherdConfigEntry) -> bool:
    """Connect to one Kiosky Shepherd server."""
    api = KioskyShepherdApi(
        async_get_clientsession(hass, verify_ssl=entry.data.get(CONF_VERIFY_SSL, True)),
        entry.data[CONF_URL],
        entry.data[CONF_API_TOKEN],
    )
    coordinator = KioskyShepherdCoordinator(hass, entry, api)
    await coordinator.async_config_entry_first_refresh()
    entry.runtime_data = coordinator
    await hass.config_entries.async_forward_entry_setups(entry, PLATFORMS)
    return True


async def async_unload_entry(hass: HomeAssistant, entry: KioskyShepherdConfigEntry) -> bool:
    """Disconnect."""
    return await hass.config_entries.async_unload_platforms(entry, PLATFORMS)
