import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { authService } from '../services/authService';

export const Register: React.FC = () => {
  const [step, setStep] = useState(1);
  const [role, setRole] = useState<'agency' | 'user' | null>(null);
  
  // Registration data
  const [formData, setFormData] = useState({
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    password: '',
    confirmPassword: ''
  });
  
  const [companyId, setCompanyId] = useState<string>('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleRoleSelect = (selectedRole: 'agency' | 'user') => {
    setRole(selectedRole);
    setStep(2);
    setError('');
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };

  const handleStep2Submit = (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    if (formData.password !== formData.confirmPassword) {
      setError("Passwords do not match");
      return;
    }
    if (formData.password.length < 8) {
      setError("Password must be at least 8 characters");
      return;
    }

    if (role === 'agency') {
      setStep(3); // Proceed to OAuth step
    } else {
      submitRegistration(); // Users don't need company OAuth
    }
  };

  const handleConnectGhl = () => {
    // Open OAuth as popup
    const clientId = import.meta.env.VITE_GHL_CLIENT_ID || '';
    const redirectUri = `${window.location.origin}/ghl-callback`;
    const ghlAuthUrl = `https://marketplace.gohighlevel.com/oauth/chooselocation?response_type=code&redirect_uri=${encodeURIComponent(redirectUri)}&client_id=${clientId}&scope=locations.readonly%20companies.readonly&state=registration`;
    
    // In a real app we open popup. Here we just simulate or open it.
    window.open(ghlAuthUrl, 'ghl_oauth', 'width=600,height=800');

    // Polling sessionStorage for companyId from popup
    const pollTimer = setInterval(() => {
      const storedCompanyId = sessionStorage.getItem('nola_registration_company_id');
      if (storedCompanyId) {
        clearInterval(pollTimer);
        setCompanyId(storedCompanyId);
        sessionStorage.removeItem('nola_registration_company_id');
        submitRegistration(storedCompanyId);
      }
    }, 1000);

    // Stop polling after 5 minutes
    setTimeout(() => clearInterval(pollTimer), 300000);
  };

  const submitRegistration = async (passedCompanyId?: string) => {
    setLoading(true);
    setError('');

    try {
      const payload = {
        firstName: formData.firstName,
        lastName: formData.lastName,
        email: formData.email,
        phone: formData.phone,
        password: formData.password,
        role: role,
        company_id: passedCompanyId || companyId || undefined
      };

      const res = await authService.register(payload);
      if (res.status === 'success') {
        setStep(4);
      } else {
        setError(res.message || 'Registration failed');
        if (role === 'agency') setStep(3);
      }
    } catch (err: any) {
      setError('Network error. Please check your connection.');
      if (role === 'agency') setStep(3);
    } finally {
      setLoading(false);
    }
  };

  const skipAndRegister = () => {
    submitRegistration();
  };

  return (
    <div className="min-h-screen bg-[#f7f7f7] dark:bg-[#111111] flex flex-col items-center justify-center p-4">
      <div className="w-full max-w-md bg-white dark:bg-[#1a1b1e] rounded-2xl p-8 shadow-xl border border-gray-100 dark:border-white/5">
        
        {/* Header */}
        <div className="text-center mb-8">
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-2">Create an Account</h1>
          <p className="text-gray-500 dark:text-gray-400 text-sm">
            {step === 1 && "Choose your role to get started"}
            {step === 2 && "Enter your basic information"}
            {step === 3 && "Connect your GoHighLevel account"}
            {step === 4 && "You're all set!"}
          </p>
        </div>

        {/* Error Banner */}
        {error && (
          <div className="mb-6 p-3 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-sm rounded-lg flex items-center gap-2">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            {error}
          </div>
        )}

        {/* Step 1: Role Selection */}
        {step === 1 && (
          <div className="space-y-4">
            <button
              onClick={() => handleRoleSelect('agency')}
              className="w-full p-6 text-left border-2 border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all flex items-start gap-4"
            >
              <div className="text-2xl mt-1">🏢</div>
              <div>
                <h3 className="font-semibold text-gray-900 dark:text-white">I'm an Agency Owner</h3>
                <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">I manage multiple client accounts in GoHighLevel.</p>
              </div>
            </button>
            <button
              onClick={() => handleRoleSelect('user')}
              className="w-full p-6 text-left border-2 border-gray-200 dark:border-gray-700 rounded-xl hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all flex items-start gap-4"
            >
              <div className="text-2xl mt-1">👤</div>
              <div>
                <h3 className="font-semibold text-gray-900 dark:text-white">I'm a Sub-account User</h3>
                <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">I'm inside a specific location or business.</p>
              </div>
            </button>
            <div className="text-center mt-6">
              <Link to="/login" className="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                Already have an account? Log in
              </Link>
            </div>
          </div>
        )}

        {/* Step 2: User Information */}
        {step === 2 && (
          <form onSubmit={handleStep2Submit} className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">First Name</label>
                <input required type="text" name="firstName" value={formData.firstName} onChange={handleInputChange} className="w-full p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-[#2a2b32] text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="John" />
              </div>
              <div>
                <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Last Name</label>
                <input required type="text" name="lastName" value={formData.lastName} onChange={handleInputChange} className="w-full p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-[#2a2b32] text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Doe" />
              </div>
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Email Address</label>
              <input required type="email" name="email" value={formData.email} onChange={handleInputChange} className="w-full p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-[#2a2b32] text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="you@example.com" />
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Phone Number</label>
              <input type="tel" name="phone" value={formData.phone} onChange={handleInputChange} className="w-full p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-[#2a2b32] text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="+1234567890" />
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Password</label>
              <input required minLength={8} type="password" name="password" value={formData.password} onChange={handleInputChange} className="w-full p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-[#2a2b32] text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="••••••••" />
            </div>
            <div>
              <label className="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Confirm Password</label>
              <input required minLength={8} type="password" name="confirmPassword" value={formData.confirmPassword} onChange={handleInputChange} className="w-full p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-[#2a2b32] text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 outline-none" placeholder="••••••••" />
            </div>
            
            <div className="pt-4 flex items-center justify-between">
              <button type="button" onClick={() => setStep(1)} className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-white font-medium text-sm">
                ← Back
              </button>
              <button disabled={loading} type="submit" className="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-xl transition-all shadow-md hover:shadow-lg disabled:opacity-50">
                {loading ? 'Processing...' : 'Continue →'}
              </button>
            </div>
          </form>
        )}

        {/* Step 3: Agency GHL OAuth */}
        {step === 3 && role === 'agency' && (
          <div className="space-y-6 text-center">
            <div className="p-4 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 rounded-xl text-sm">
              Connect your GoHighLevel account so we can automatically import your agency details and sub-accounts.
            </div>
            
            <button 
              onClick={handleConnectGhl} 
              disabled={loading}
              className="w-full bg-[#1188e6] hover:bg-[#0f7dc9] text-white font-bold py-3.5 px-6 rounded-xl shadow-md transition-all flex items-center justify-center gap-2"
            >
              {loading ? 'Connecting...' : 'Connect GoHighLevel'}
              <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
            </button>
            
            <div>
              <button 
                onClick={skipAndRegister} 
                disabled={loading}
                className="text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white text-sm font-medium transition-colors"
               >
                Skip for now (I'll connect later)
              </button>
            </div>
          </div>
        )}

        {/* Step 4: Success */}
        {step === 4 && (
          <div className="text-center py-6">
            <div className="w-20 h-20 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
              <svg className="w-10 h-10 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <h2 className="text-xl font-bold text-gray-900 dark:text-white mb-2">Registration Complete!</h2>
            <p className="text-gray-500 dark:text-gray-400 mb-8">Your account has been successfully created. You can now log in to your dashboard.</p>
            <Link to="/login" className="block w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-6 rounded-xl transition-all shadow-md">
              Go to Login
            </Link>
          </div>
        )}

      </div>
    </div>
  );
};
