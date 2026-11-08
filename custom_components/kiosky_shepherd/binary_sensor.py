"""Online, charging, screen and policy sensors."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

from homeassistant.components.binary_sensor import (
    BinarySensorDeviceClass,
    BinarySensorEntity,
    BinarySensorEntityDescription,
)
from homeassistant.const import EntityCategory
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback

from .coordinator import KioskyShepherdConfigEntry, KioskyShepherdCoordinator
from .entity import KioskyShepherdEntity, add_entities_for_new_devices


@dataclass(frozen=True, kw_only=True)
class KioskyBinaryDescription(BinarySensorEntityDescription):
    """How to read one on/off state."""

    value_fn: Callable[[dict[str, Any], dict[str, Any]], bool | None]


def _bool(v: Any) -> bool | None:
    return v if isinstance(v, bool) else None


BINARY_SENSORS: tuple[KioskyBinaryDescription, ...] = (
    KioskyBinaryDescription(
        key="online",
        name="Online",
        device_class=BinarySensorDeviceClass.CONNECTIVITY,
        value_fn=lambda d, s: bool(d.get("online")),
    ),
    KioskyBinaryDescription(
        key="charging",
        name="Charging",
        device_class=BinarySensorDeviceClass.BATTERY_CHARGING,
        value_fn=lambda d, s: _bool(s.get("charging")),
    ),
    KioskyBinaryDescription(
        key="screen_on",
        name="Screen on",
        icon="mdi:tablet",
        value_fn=lambda d, s: _bool(s.get("screen_on")),
    ),
    KioskyBinaryDescription(
        key="policy_applied",
        name="Policy applied",
        icon="mdi:shield-check-outline",
        entity_category=EntityCategory.DIAGNOSTIC,
        value_fn=lambda d, s: bool(d.get("policy_current")),
    ),
)


async def async_setup_entry(
    hass: HomeAssistant, entry: KioskyShepherdConfigEntry, async_add_entities: AddEntitiesCallback
) -> None:
    """Set up binary sensors for every tablet."""
    add_entities_for_new_devices(
        entry, async_add_entities, lambda c, uid: [KioskyBinarySensor(c, uid, desc) for desc in BINARY_SENSORS]
    )


class KioskyBinarySensor(KioskyShepherdEntity, BinarySensorEntity):
    """One on/off state of a tablet."""

    entity_description: KioskyBinaryDescription

    def __init__(self, coordinator: KioskyShepherdCoordinator, uid: str, description: KioskyBinaryDescription) -> None:
        super().__init__(coordinator, uid, description.key)
        self.entity_description = description

    @property
    def is_on(self) -> bool | None:
        """The current state, or None when the tablet hasn't reported it."""
        return self.entity_description.value_fn(self.device, self.state_report)
