import { useEffect, useState } from 'react';
import { getAccountSettings, saveAccountSettings } from '../utils/settingsStorage';

export function useGhlLocation() {
    const [locationId, setLocationId] = useState<string | null>(getAccountSettings().ghlLocationId || null);

    useEffect(() => {
        let urlLocation: string | null = null;
        const possibleKeys = ['location', 'locationId', 'location_id', 'id'];

        // 1. Parse standard URL search parameters
        const searchParams = new URLSearchParams(window.location.search);
        for (const key of possibleKeys) {
            if (searchParams.get(key)) {
                urlLocation = searchParams.get(key);
                break;
            }
        }

        // 2. Parse hash string if search params didn't have it (e.g. #/settings?location=123)
        if (!urlLocation && window.location.hash.includes('?')) {
            const hashString = window.location.hash.split('?')[1];
            if (hashString) {
                const hashParams = new URLSearchParams(hashString);
                for (const key of possibleKeys) {
                    if (hashParams.get(key)) {
                        urlLocation = hashParams.get(key);
                        break;
                    }
                }
            }
        }

        // 3. Fallback: Check the entire href just in case
        if (!urlLocation) {
            try {
                const url = new URL(window.location.href);
                for (const key of possibleKeys) {
                    if (url.searchParams.get(key)) {
                        urlLocation = url.searchParams.get(key);
                        break;
                    }
                }
            } catch (e) {
                // Ignore URL parsing errors
            }
        }

        console.log("NOLA SMS: Detected GHL Location URL Param:", urlLocation, "Full URL:", window.location.href);

        if (urlLocation && urlLocation !== locationId) {
            setLocationId(urlLocation);

            // Auto-save to local storage if it's different from what we had
            const accountSettings = getAccountSettings();
            if (accountSettings.ghlLocationId !== urlLocation) {
                saveAccountSettings({
                    ...accountSettings,
                    ghlLocationId: urlLocation,
                });
            }
        }
    }, [locationId]);

    return locationId;
}
