"""Base entity: one Home Assistant device per tablet."""

from __future__ import annotations

from collections.abc import Callable, Iterable
from typing import Any

from homeassistant.core import callback
from homeassistant.helpers.device_registry import DeviceInfo
from homeassistant.helpers.entity import Entity
from homeassistant.helpers.update_coordinator import CoordinatorEntity

from .const import DOMAIN
from .coordinator import KioskyShepherdConfigEntry, KioskyShepherdCoordinator


class KioskyShepherdEntity(CoordinatorEntity[KioskyShepherdCoordinator]):
    """An entity belonging to one managed tablet."""

    _attr_has_entity_name = True

    def __init__(self, coordinator: KioskyShepherdCoordinator, uid: str, key: str) -> None:
        super().__init__(coordinator)
        self.uid = uid
        self._attr_unique_id = f"{uid}_{key}"
        d = self.device
        self._attr_device_info = DeviceInfo(
            identifiers={(DOMAIN, uid)},
            name=d.get("name"),
            manufacturer=d.get("manufacturer"),
            model=d.get("model"),
            sw_version=d.get("app_version"),
            configuration_url=f"{coordinator.api.url}/admin/#/devices/{d.get('id')}",
        )

    @property
    def device(self) -> dict[str, Any]:
        """The tablet's latest data from the server."""
        return self.coordinator.data.get(self.uid, {})

    @property
    def state_report(self) -> dict[str, Any]:
        """The status the tablet last reported (battery, Wi-Fi, current page…)."""
        state = self.device.get("state")
        return state if isinstance(state, dict) else {}

    @property
    def available(self) -> bool:
        """Unavailable once the device is retired or out of the token's reach."""
        return super().available and self.uid in self.coordinator.data


def add_entities_for_new_devices(
    entry: KioskyShepherdConfigEntry,
    add_entities: Callable[[Iterable[Entity]], None],
    build: Callable[[KioskyShepherdCoordinator, str], list[Entity]],
) -> None:
    """Add entities now, and again for tablets enrolled later."""
    coordinator = entry.runtime_data
    known: set[str] = set()

    @callback
    def _add_new() -> None:
        new = [uid for uid in coordinator.data if uid not in known]
        if not new:
            return
        known.update(new)
        add_entities([entity for uid in new for entity in build(coordinator, uid)])

    _add_new()
    entry.async_on_unload(coordinator.async_add_listener(_add_new))
