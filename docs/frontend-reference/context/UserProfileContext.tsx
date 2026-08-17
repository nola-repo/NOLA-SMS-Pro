import { createContext, useContext } from 'react';
import type { UserProfile } from '../hooks/useUserProfile';

export const UserProfileContext = createContext<UserProfile | null>(null);

export const useUserProfileContext = () => useContext(UserProfileContext);
