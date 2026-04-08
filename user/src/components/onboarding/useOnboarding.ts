import { useState, useEffect, useCallback } from "react";

const STORAGE_DONE_KEY = "nola_onboarding_done";
const STORAGE_STEP_KEY = "nola_onboarding_step";
const TOTAL_STEPS = 7;

export interface UseOnboardingReturn {
  isOpen: boolean;
  currentStep: number;
  totalSteps: number;
  isComplete: boolean;
  open: (step?: number) => void;
  close: () => void;
  next: () => void;
  back: () => void;
  complete: () => void;
  goToStep: (step: number) => void;
}

export function useOnboarding(): UseOnboardingReturn {
  const [isComplete] = useState<boolean>(() => {
    return localStorage.getItem(STORAGE_DONE_KEY) === "true";
  });

  const [isOpen, setIsOpen] = useState<boolean>(false);

  const [currentStep, setCurrentStep] = useState<number>(() => {
    const saved = localStorage.getItem(STORAGE_STEP_KEY);
    return saved ? Math.min(parseInt(saved, 10), TOTAL_STEPS - 1) : 0;
  });

  // Persist step progress
  useEffect(() => {
    localStorage.setItem(STORAGE_STEP_KEY, String(currentStep));
  }, [currentStep]);

  // Listen for open-onboarding custom event
  useEffect(() => {
    const handler = (e: CustomEvent<{ step?: number }>) => {
      setCurrentStep(e.detail?.step ?? 0);
      setIsOpen(true);
    };
    window.addEventListener("open-onboarding", handler as EventListener);
    return () =>
      window.removeEventListener("open-onboarding", handler as EventListener);
  }, []);

  const open = useCallback((step = 0) => {
    setCurrentStep(step);
    setIsOpen(true);
  }, []);

  const close = useCallback(() => {
    localStorage.setItem(STORAGE_DONE_KEY, "true");
    setIsOpen(false);
  }, []);

  const next = useCallback(() => {
    setCurrentStep((prev) => Math.min(prev + 1, TOTAL_STEPS - 1));
  }, []);

  const back = useCallback(() => {
    setCurrentStep((prev) => Math.max(prev - 1, 0));
  }, []);

  const complete = useCallback(() => {
    localStorage.setItem(STORAGE_DONE_KEY, "true");
    localStorage.removeItem(STORAGE_STEP_KEY);
    setIsOpen(false);
  }, []);

  const goToStep = useCallback((step: number) => {
    setCurrentStep(Math.max(0, Math.min(step, TOTAL_STEPS - 1)));
  }, []);

  return {
    isOpen,
    currentStep,
    totalSteps: TOTAL_STEPS,
    isComplete,
    open,
    close,
    next,
    back,
    complete,
    goToStep,
  };
}
