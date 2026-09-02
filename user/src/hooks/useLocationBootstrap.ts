import { useCallback, useEffect, useState } from 'react';
import {
  addLocationBootstrapListener,
  getCachedLocationBootstrapState,
  isValidGhlLocationId,
  resolveLocationBootstrap,
  type LocationBootstrapState,
} from '../utils/locationBootstrap';

export const useLocationBootstrap = (
  locationId: string,
  enabled = true,
): { state: LocationBootstrapState; retry: () => void } => {
  const [state, setState] = useState<LocationBootstrapState>(() => getCachedLocationBootstrapState(locationId));

  const run = useCallback((force = false) => {
    if (!enabled || !locationId) {
      setState({ status: 'idle', locationId });
      return;
    }

    if (!isValidGhlLocationId(locationId)) {
      setState({
        status: 'error',
        locationId,
        message: 'Invalid GHL location id.',
        response: {
          location_id: locationId,
          contacts_can_load: false,
          next_action: 'show_retry',
          code: 'INVALID_GHL_LOCATION_ID',
        },
      });
      return;
    }

    void resolveLocationBootstrap(locationId, { force }).then((nextState) => {
      setState(nextState);
    });
  }, [enabled, locationId]);

  useEffect(() => {
    setState(getCachedLocationBootstrapState(locationId));
    run(false);
  }, [locationId, run]);

  useEffect(() => {
    return addLocationBootstrapListener((nextState) => {
      if (!locationId || nextState.locationId === locationId) {
        setState(nextState);
      }
    });
  }, [locationId]);

  useEffect(() => {
    const handleSessionUpdated = () => run(true);
    window.addEventListener('nola-auth-session-updated', handleSessionUpdated);
    return () => window.removeEventListener('nola-auth-session-updated', handleSessionUpdated);
  }, [run]);

  return {
    state,
    retry: () => run(true),
  };
};
