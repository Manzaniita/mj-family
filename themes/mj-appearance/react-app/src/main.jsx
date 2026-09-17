import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import { ErrorBoundary } from '@/components/ui/ErrorBoundary';
import { watchGlobalErrors } from '@/lib/errors';
import './styles/globals.css';

// Errores fuera de React (scripts, promesas sin catch) también quedan registrados.
watchGlobalErrors();

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary scope="app">
      <App />
    </ErrorBoundary>
  </React.StrictMode>
);
