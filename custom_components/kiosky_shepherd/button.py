"""Buttons for common commands. They need an API token with the operator role."""

from __future__ import annotations

from homeassistant.components.button import ButtonDeviceClass, ButtonEntity, ButtonEntityDescription
from homeassistant.const import EntityCategory
from homeassistant.core import HomeAssistant
from homeassistant.exceptions import HomeAssistantError
from homeassistant.helpers.entity_platform import AddEntitiesCallback

from .api import KioskyShepherdError
from .coordinator import KioskyShepherdConfigEntry, KioskyShepherdCoordinator
from .entity import KioskyShepherdEntity, add_entities_for_new_devices

BUTTONS: tuple[ButtonEntityDescription, ...] = (
    ButtonEntityDescription(key="reload", name="Reload page", icon="mdi:refresh"),
    ButtonEntityDescription(key="go_home", name="Back to start page", icon="mdi:home"),
    ButtonEntityDescription(key="wake_screen", name="Wake screen", icon="mdi:monitor-eye"),
    ButtonEntityDescription(key="identify", name="Identify", device_class=ButtonDeviceClass.IDENTIFY),
    ButtonEntityDescription(key="screenshot", name="Take screenshot", icon="mdi:camera", entity_category=EntityCategory.DIAGNOSTIC),
    ButtonEntityDescription(key="clear_cache", name="Clear cache", icon="mdi:broom", entity_category=EntityCategory.CONFIG),
    ButtonEntityDescription(
        key="restart_app", name="Restart Kiosky", device_class=ButtonDeviceClass.RESTART, entity_category=EntityCategory.CONFIG
    ),
    ButtonEntityDescription(
        key="reboot", name="Reboot tablet", icon="mdi:restart-alert", entity_category=EntityCategory.CONFIG,
        entity_registry_enabled_default=False,
    ),
)


async def async_setup_entry(
    hass: HomeAssistant, entry: KioskyShepherdConfigEntry, async_add_entities: AddEntitiesCallback
) -> None:
    """Set up command buttons for every tablet."""
    add_entities_for_new_devices(
        entry, async_add_entities, lambda c, uid: [KioskyButton(c, uid, desc) for desc in BUTTONS]
    )


class KioskyButton(KioskyShepherdEntity, ButtonEntity):
    """Queues one command; the tablet runs it at its next check-in."""

    def __init__(self, coordinator: KioskyShepherdCoordinator, uid: str, description: ButtonEntityDescription) -> None:
        super().__init__(coordinator, uid, f"button_{description.key}")
        self.entity_description = description

    async def async_press(self) -> None:
        """Send the command."""
        try:
            await self.coordinator.api.send_command(int(self.device["id"]), self.entity_description.key)
        except (KioskyShepherdError, KeyError) as err:
            raise HomeAssistantError(f"Kiosky Shepherd: {err}") from err
