"""Polls the device list every 30 seconds."""

from __future__ import annotations

import logging
from typing import Any

from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import ConfigEntryAuthFailed
from homeassistant.helpers.update_coordinator import DataUpdateCoordinator, UpdateFailed

from .api import KioskyShepherdApi, KioskyShepherdAuthError, KioskyShepherdError
from .const import DOMAIN, SCAN_INTERVAL

_LOGGER = logging.getLogger(__name__)

type KioskyShepherdConfigEntry = ConfigEntry[KioskyShepherdCoordinator]


class KioskyShepherdCoordinator(DataUpdateCoordinator[dict[str, dict[str, Any]]]):
    """Device data keyed by the device's uid (its stable public id)."""

    config_entry: KioskyShepherdConfigEntry

    def __init__(self, hass: HomeAssistant, entry: KioskyShepherdConfigEntry, api: KioskyShepherdApi) -> None:
        super().__init__(hass, _LOGGER, config_entry=entry, name=DOMAIN, update_interval=SCAN_INTERVAL)
        self.api = api

    async def _async_update_data(self) -> dict[str, dict[str, Any]]:
        try:
            devices = await self.api.devices()
        except KioskyShepherdAuthError as err:
            raise ConfigEntryAuthFailed(str(err)) from err
        except KioskyShepherdError as err:
            raise UpdateFailed(str(err)) from err
        return {d["uid"]: d for d in devices if isinstance(d, dict) and "uid" in d}
