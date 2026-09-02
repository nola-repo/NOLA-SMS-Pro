import React, { useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { isAuthenticated } from "../../services/authService";
import { AppLoadingScreen } from "../ui/AppLoadingScreen";
import { isGhlEmbeddedRequest } from "../../utils/ghlEmbedded";

export const RedirectToBackend: React.FC<{ path: string }> = ({ path }) => {
  const alreadySignedIn = isAuthenticated();
  const navigate = useNavigate();
  const isGhlRequest = isGhlEmbeddedRequest();

  useEffect(() => {
    if (alreadySignedIn) {
      navigate({ pathname: "/", search: window.location.search }, { replace: true });
      return;
    }

    if (isGhlRequest) {
      navigate({ pathname: "/", search: window.location.search }, { replace: true });
      return;
    }

    window.location.replace(`${import.meta.env.VITE_API_BASE || ''}${path}${window.location.search}`);
  }, [path, alreadySignedIn, isGhlRequest, navigate]);

  return <AppLoadingScreen message="Redirecting..." subtext="Connecting to authentication provider..." />;
};

export const RedirectInstallRegistration: React.FC = () => {
  useEffect(() => {
    const target = new URL(`${import.meta.env.VITE_API_BASE || window.location.origin}/install-register.php`);
    const params = new URLSearchParams(window.location.search);
    params.forEach((value, key) => target.searchParams.set(key, value));
    window.location.replace(target.toString());
  }, []);

  return <AppLoadingScreen message="Opening Installation Setup" subtext="Redirecting to the secure setup page..." />;
};
