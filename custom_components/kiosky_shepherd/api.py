"""Small async client for the Kiosky Shepherd admin API (authenticated with an API token)."""

from __future__ import annotations

from typing import Any

import aiohttp


class KioskyShepherdError(Exception):
    """The server answered with an error, or couldn't be reached."""


class KioskyShepherdAuthError(KioskyShepherdError):
    """The API token was refused."""


class KioskyShepherdApi:
    """Calls /api/v1/admin/... with `Authorization: Bearer shp_...`."""

    def __init__(self, session: aiohttp.ClientSession, url: str, token: str) -> None:
        self._session = session
        self.url = url.rstrip("/")
        self._token = token

    async def _request(self, method: str, path: str, body: dict[str, Any] | None = None) -> Any:
        try:
            async with self._session.request(
                method,
                f"{self.url}/api/v1/admin{path}",
                headers={"Authorization": f"Bearer {self._token}", "Accept": "application/json"},
                json=body,
                timeout=aiohttp.ClientTimeout(total=15),
            ) as resp:
                data = None if resp.status == 204 else await resp.json(content_type=None)
                if resp.status == 401:
                    raise KioskyShepherdAuthError("The API token was refused.")
                if resp.status >= 400:
                    message = data.get("error", {}).get("message") if isinstance(data, dict) else None
                    raise KioskyShepherdError(message or f"The server answered HTTP {resp.status}.")
                return data
        except (aiohttp.ClientError, TimeoutError, ValueError) as err:
            raise KioskyShepherdError(f"Can't reach {self.url}: {err}") from err

    async def whoami(self) -> dict[str, Any]:
        """The token's user and the server version."""
        return await self._request("GET", "/session")

    async def devices(self) -> list[dict[str, Any]]:
        """Every active device the token can see."""
        data = await self._request("GET", "/devices?status=active")
        return data.get("items", []) if isinstance(data, dict) else []

    async def send_command(self, device_id: int, command: str, params: dict[str, Any] | None = None) -> dict[str, Any]:
        """Queue a command; the tablet runs it at its next check-in."""
        return await self._request("POST", f"/devices/{device_id}/commands", {"type": command, "params": params or {}})
