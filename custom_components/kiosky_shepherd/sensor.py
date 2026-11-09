"""Battery, Wi-Fi, last seen, version, page, uptime and screen sensors."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
from typing import Any

from homeassistant.components.sensor import (
    SensorDeviceClass,
    SensorEntity,
    SensorEntityDescription,
    SensorStateClass,
)
from homeassistant.const import PERCENTAGE, SIGNAL_STRENGTH_DECIBELS_MILLIWATT, EntityCategory, UnitOfTemperature
from homeassistant.core import HomeAssistant
from homeassistant.helpers.entity_platform import AddEntitiesCallback
from homeassistant.util import dt as dt_util

from .coordinator import KioskyShepherdConfigEntry, KioskyShepherdCoordinator
from .entity import KioskyShepherdEntity, add_entities_for_new_devices


@dataclass(frozen=True, kw_only=True)
class KioskySensorDescription(SensorEntityDescription):
    """How to read one sensor from a device and its status report."""

    value_fn: Callable[[dict[str, Any], dict[str, Any]], Any]


def _int(v: Any) -> int | None:
    return v if isinstance(v, int) and not isinstance(v, bool) else None


def _number(v: Any) -> float | None:
    return float(v) if isinstance(v, (int, float)) and not isinstance(v, bool) else None


def _text(v: Any) -> str | None:
    return v[:255] if isinstance(v, str) and v else None


SENSORS: tuple[KioskySensorDescription, ...] = (
    KioskySensorDescription(
        key="battery",
        name="Battery",
        device_class=SensorDeviceClass.BATTERY,
        native_unit_of_measurement=PERCENTAGE,
        state_class=SensorStateClass.MEASUREMENT,
        value_fn=lambda d, s: _int(s.get("battery_level")),
    ),
    KioskySensorDescription(
        key="battery_temperature",
        name="Battery temperature",
        device_class=SensorDeviceClass.TEMPERATURE,
        native_unit_of_measurement=UnitOfTemperature.CELSIUS,
        state_class=SensorStateClass.MEASUREMENT,
        entity_category=EntityCategory.DIAGNOSTIC,
        value_fn=lambda d, s: _number(s.get("battery_temperature")),
    ),
    KioskySensorDescription(
        key="screen_state",
        name="Screen",
        icon="mdi:monitor",
        device_class=SensorDeviceClass.ENUM,
        options=["normal", "dim", "off"],
        value_fn=lambda d, s: s.get("screen_state") if s.get("screen_state") in ("normal", "dim", "off") else None,
    ),
    KioskySensorDescription(
        key="wifi_signal",
        name="Wi-Fi signal",
        device_class=SensorDeviceClass.SIGNAL_STRENGTH,
        native_unit_of_measurement=SIGNAL_STRENGTH_DECIBELS_MILLIWATT,
        state_class=SensorStateClass.MEASUREMENT,
        entity_category=EntityCategory.DIAGNOSTIC,
        entity_registry_enabled_default=False,
        value_fn=lambda d, s: _int(s.get("wifi_rssi")),
    ),
    KioskySensorDescription(
        key="last_seen",
        name="Last seen",
        device_class=SensorDeviceClass.TIMESTAMP,
        entity_category=EntityCategory.DIAGNOSTIC,
        value_fn=lambda d, s: dt_util.parse_datetime(d["last_seen_at"]) if d.get("last_seen_at") else None,
    ),
    KioskySensorDescription(
        key="current_page",
        name="Current page",
        icon="mdi:web",
        value_fn=lambda d, s: _text(s.get("current_url")),
    ),
    KioskySensorDescription(
        key="profile",
        name="Profile",
        icon="mdi:card-account-details-outline",
        entity_category=EntityCategory.DIAGNOSTIC,
        value_fn=lambda d, s: d.get("profile_name"),
    ),
    KioskySensorDescription(
        key="app_version",
        name="Kiosky version",
        icon="mdi:package-variant",
        entity_category=EntityCategory.DIAGNOSTIC,
        value_fn=lambda d, s: d.get("app_version"),
    ),
    KioskySensorDescription(
        key="uptime_7d",
        name="Online last 7 days",
        icon="mdi:percent-circle-outline",
        native_unit_of_measurement=PERCENTAGE,
        state_class=SensorStateClass.MEASUREMENT,
        entity_category=EntityCategory.DIAGNOSTIC,
        value_fn=lambda d, s: d.get("uptime_7d"),
    ),
)


async def async_setup_entry(
    hass: HomeAssistant, entry: KioskyShepherdConfigEntry, async_add_entities: AddEntitiesCallback
) -> None:
    """Set up sensors for every tablet."""
    add_entities_for_new_devices(
        entry, async_add_entities, lambda c, uid: [KioskySensor(c, uid, desc) for desc in SENSORS]
    )


class KioskySensor(KioskyShepherdEntity, SensorEntity):
    """One reading from a tablet."""

    entity_description: KioskySensorDescription

    def __init__(self, coordinator: KioskyShepherdCoordinator, uid: str, description: KioskySensorDescription) -> None:
        super().__init__(coordinator, uid, description.key)
        self.entity_description = description

    @property
    def native_value(self) -> Any:
        """The current value."""
        return self.entity_description.value_fn(self.device, self.state_report)
