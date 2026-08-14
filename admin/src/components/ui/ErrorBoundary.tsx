import { Component, type ErrorInfo, type ReactNode } from "react";
import { FiAlertTriangle, FiRefreshCw, FiChevronDown, FiChevronUp, FiCopy, FiCheck } from "react-icons/fi";

export interface ErrorBoundaryProps {
  children: ReactNode;
  fallback?: ReactNode | ((error: Error, reset: () => void) => ReactNode);
  onReset?: () => void;
  title?: string;
  appName?: string;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
  errorInfo: ErrorInfo | null;
  showDetails: boolean;
  copied: boolean;
}

export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  public override state: ErrorBoundaryState = {
    hasError: false,
    error: null,
    errorInfo: null,
    showDetails: false,
    copied: false,
  };

  public static getDerivedStateFromError(error: Error): Partial<ErrorBoundaryState> {
    return { hasError: true, error };
  }

  public override componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    this.setState({ errorInfo });
    console.error("[Admin ErrorBoundary caught an unhandled error]:", error, errorInfo);
  }

  public handleReset = (): void => {
    this.setState({
      hasError: false,
      error: null,
      errorInfo: null,
      showDetails: false,
      copied: false,
    });
    if (this.props.onReset) {
      this.props.onReset();
    }
  };

  public handleReload = (): void => {
    window.location.reload();
  };

  public toggleDetails = (): void => {
    this.setState((prev) => ({ showDetails: !prev.showDetails }));
  };

  public copyErrorToClipboard = async (): Promise<void> => {
    const { error, errorInfo } = this.state;
    const errorDetails = `Error: ${error?.message || "Unknown error"}\n\nStack:\n${error?.stack || "No stack trace"}\n\nComponent Stack:\n${errorInfo?.componentStack || "No component stack"}`;
    
    try {
      await navigator.clipboard.writeText(errorDetails);
      this.setState({ copied: true });
      setTimeout(() => this.setState({ copied: false }), 2000);
    } catch {
      console.warn("Failed to copy error details to clipboard");
    }
  };

  public override render(): ReactNode {
    const { hasError, error, errorInfo, showDetails, copied } = this.state;
    const { children, fallback, title, appName = "NOLA SMS Pro Admin" } = this.props;

    if (hasError) {
      if (typeof fallback === "function") {
        return fallback(error || new Error("Unknown error"), this.handleReset);
      }
      if (fallback !== undefined && fallback !== null) {
        return fallback;
      }

      return (
        <div className="min-h-screen w-full flex items-center justify-center p-4 sm:p-6 bg-[#f8fafc] dark:bg-[#0f1117] text-[#1e293b] dark:text-[#e2e8f0] antialiased">
          <div className="w-full max-w-xl rounded-2xl border border-[#e2e8f0] dark:border-white/10 bg-white/95 dark:bg-[#161822]/95 backdrop-blur-md shadow-2xl p-6 sm:p-8 flex flex-col items-center text-center animate-in fade-in zoom-in-95 duration-200">
            {/* Warning Icon Badge */}
            <div className="h-16 w-16 rounded-2xl bg-amber-500/10 dark:bg-amber-400/10 border border-amber-500/20 dark:border-amber-400/20 flex items-center justify-center text-amber-500 dark:text-amber-400 mb-5 shadow-inner">
              <FiAlertTriangle className="h-8 w-8 animate-pulse" />
            </div>

            {/* Error Header */}
            <span className="text-xs font-bold tracking-widest uppercase text-[#3b82f6] dark:text-[#60a5fa] mb-1">
              {appName}
            </span>
            <h1 className="text-xl sm:text-2xl font-black tracking-tight text-[#0f172a] dark:text-white mb-2">
              {title || "Something went wrong"}
            </h1>
            <p className="text-sm text-[#64748b] dark:text-[#94a3b8] max-w-md mb-6 leading-relaxed">
              An unexpected runtime exception was caught in the administrator portal. The application prevented a total crash, and you can try reloading or recovering the view.
            </p>

            {/* Actions */}
            <div className="w-full flex flex-col sm:flex-row items-center justify-center gap-3 mb-6">
              <button
                type="button"
                onClick={this.handleReset}
                className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-gradient-to-r from-[#2563eb] to-[#1d4ed8] hover:from-[#1d4ed8] hover:to-[#1e40af] text-white text-sm font-semibold shadow-md shadow-blue-500/20 hover:shadow-lg hover:shadow-blue-500/30 transition-all active:scale-[0.98]"
              >
                <FiRefreshCw className="h-4 w-4" />
                Try Again
              </button>
              <button
                type="button"
                onClick={this.handleReload}
                className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-[#f1f5f9] dark:bg-white/10 hover:bg-[#e2e8f0] dark:hover:bg-white/15 text-[#334155] dark:text-[#f8fafc] text-sm font-semibold border border-[#cbd5e1] dark:border-white/10 transition-all active:scale-[0.98]"
              >
                Reload Application
              </button>
            </div>

            {/* Technical Details Accordion */}
            {error && (
              <div className="w-full border-t border-[#e2e8f0] dark:border-white/10 pt-4 text-left">
                <button
                  type="button"
                  onClick={this.toggleDetails}
                  className="w-full flex items-center justify-between text-xs font-semibold text-[#64748b] dark:text-[#94a3b8] hover:text-[#0f172a] dark:hover:text-white py-1 transition-colors"
                >
                  <span>Technical details</span>
                  {showDetails ? <FiChevronUp className="h-4 w-4" /> : <FiChevronDown className="h-4 w-4" />}
                </button>

                {showDetails && (
                  <div className="mt-3 rounded-xl bg-[#0f172a] dark:bg-[#090a0f] border border-black/20 dark:border-white/10 p-4 text-left font-mono text-xs text-[#f8fafc] overflow-hidden">
                    <div className="flex items-center justify-between pb-2 mb-2 border-b border-white/10">
                      <span className="text-red-400 font-semibold truncate">
                        {error.name}: {error.message}
                      </span>
                      <button
                        type="button"
                        onClick={this.copyErrorToClipboard}
                        className="inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded bg-white/10 hover:bg-white/20 text-white transition-colors"
                        title="Copy error details"
                      >
                        {copied ? <FiCheck className="h-3 w-3 text-emerald-400" /> : <FiCopy className="h-3 w-3" />}
                        {copied ? "Copied" : "Copy"}
                      </button>
                    </div>
                    <div className="max-h-48 overflow-y-auto space-y-2 text-[11px] text-gray-300 scrollbar-thin">
                      {error.stack && (
                        <div>
                          <div className="text-gray-400 font-semibold mb-1">Stack trace:</div>
                          <pre className="whitespace-pre-wrap break-all text-[10px] text-gray-300 bg-black/30 p-2 rounded">
                            {error.stack}
                          </pre>
                        </div>
                      )}
                      {errorInfo?.componentStack && (
                        <div>
                          <div className="text-gray-400 font-semibold mb-1">Component stack:</div>
                          <pre className="whitespace-pre-wrap break-all text-[10px] text-gray-300 bg-black/30 p-2 rounded">
                            {errorInfo.componentStack}
                          </pre>
                        </div>
                      )}
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      );
    }

    return children;
  }
}

export default ErrorBoundary;
