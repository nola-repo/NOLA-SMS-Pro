import React from 'react';
import { BrowserRouter } from 'react-router-dom';
import { AgencyProvider } from './context/AgencyContext.tsx';
import { AppRoutes } from './routes.tsx';

const App = () => (
  <AgencyProvider>
    <BrowserRouter>
      <AppRoutes />
    </BrowserRouter>
  </AgencyProvider>
);

export default App;
