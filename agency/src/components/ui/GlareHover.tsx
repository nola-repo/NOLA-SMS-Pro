import React, { useRef } from 'react';

interface GlareHoverProps {
    width?: string;
    height?: string;
    background?: string;
    borderRadius?: string;
    borderColor?: string;
    children?: React.ReactNode;
    glareColor?: string;
    glareOpacity?: number;
    glareAngle?: number;
    glareSize?: number;
    transitionDuration?: number;
    playOnce?: boolean;
    className?: string;
    style?: React.CSSProperties;
}

const GlareHover: React.FC<GlareHoverProps> = ({
    width,
    height,
    borderRadius = '9999px',
    borderColor = 'transparent',
    children,
    glareColor = '#ffffff',
    glareOpacity = 0.3,
    glareAngle = -30,
    glareSize = 300,
    transitionDuration = 800,
    playOnce = false,
    className = '',
    style = {}
}) => {
    const hex = glareColor.replace('#', '');
    let rgba = glareColor;
    if (/^[\dA-Fa-f]{6}$/.test(hex)) {
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const b = parseInt(hex.slice(4, 6), 16);
        rgba = `rgba(${r}, ${g}, ${b}, ${glareOpacity})`;
    } else if (/^[\dA-Fa-f]{3}$/.test(hex)) {
        const r = parseInt(hex[0] + hex[0], 16);
        const g = parseInt(hex[1] + hex[1], 16);
        const b = parseInt(hex[2] + hex[2], 16);
        rgba = `rgba(${r}, ${g}, ${b}, ${glareOpacity})`;
    }

    const overlayRef = useRef<HTMLDivElement | null>(null);

    const animateIn = () => {
        const el = overlayRef.current;
        if (!el) return;
        el.style.transition = 'none';
        el.style.backgroundPosition = '-100% -100%, 0 0';
        // Force repaint
        void el.offsetHeight;
        el.style.transition = `${transitionDuration}ms ease`;
        el.style.backgroundPosition = '100% 100%, 0 0';
    };

    const animateOut = () => {
        const el = overlayRef.current;
        if (!el) return;
        if (playOnce) {
            el.style.transition = 'none';
            el.style.backgroundPosition = '-100% -100%, 0 0';
        } else {
            el.style.transition = `${transitionDuration}ms ease`;
            el.style.backgroundPosition = '-100% -100%, 0 0';
        }
    };

    const overlayStyle: React.CSSProperties = {
        position: 'absolute',
        inset: 0,
        background: `linear-gradient(${glareAngle}deg,
        hsla(0,0%,0%,0) 60%,
        ${rgba} 70%,
        hsla(0,0%,0%,0) 100%)`,
        backgroundSize: `${glareSize}% ${glareSize}%, 100% 100%`,
        backgroundRepeat: 'no-repeat',
        backgroundPosition: '-100% -100%, 0 0',
        pointerEvents: 'none',
        borderRadius,
    };

    return (
        <div
            className={`relative ${className}`}
            style={{
                width,
                height,
                borderRadius,
                borderColor,
                border: borderColor !== 'transparent' ? `1px solid ${borderColor}` : undefined,
                ...style
            }}
            onMouseEnter={animateIn}
            onMouseLeave={animateOut}
        >
            <div className="absolute inset-0 overflow-hidden" style={{ borderRadius: 'inherit' }}>
                <div ref={overlayRef} style={overlayStyle} />
            </div>
            <div className="relative z-10 w-full h-full">
                {children}
            </div>
        </div>
    );
};

export default GlareHover;
