import { hasGhlLaunchSignalInCurrentUrl } from "./ghlLocationDetection";

export const isGhlEmbeddedRequest = (): boolean => {
  const hasGhlParam = hasGhlLaunchSignalInCurrentUrl();

  let isIframe = false;
  try {
    isIframe = window.self !== window.top;
  } catch {
    isIframe = true;
  }

  let savedGhlFrame = false;
  try {
    savedGhlFrame = sessionStorage.getItem("nola_is_ghl_frame") === "true";
  } catch {
    // Storage can be blocked in embedded contexts.
  }

  if (hasGhlParam || isIframe) {
    try {
      sessionStorage.setItem("nola_is_ghl_frame", "true");
    } catch {
      // ignore
    }
  }

  return hasGhlParam || isIframe || savedGhlFrame;
};
