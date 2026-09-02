/**
 * useGhlLocation — backward-compatible hook that returns the current GHL location ID.
 *
 * Previously read URL params directly and managed its own state, which meant
 * components couldn't react to subaccount changes after the initial mount.
 *
 * Now delegates to LocationContext, which handles all detection sources:
 *   - URL params (initial load and polling)
 *   - GHL postMessage (subaccount switching in iframe)
 *   - ghl-location-set custom events (manual Settings input)
 *
 * All existing callers of useGhlLocation() continue to work without changes.
 */
import { useLocationId } from '../context/LocationContext';

export function useGhlLocation(): string | null {
    const { locationId } = useLocationId();
    return locationId || null;
}
