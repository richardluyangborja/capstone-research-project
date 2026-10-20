import { Route, Routes } from 'react-router-dom'
import DashboardShell from '../components/layout/DashboardShell'
import PublicLayout from '../components/layout/PublicLayout'
import ProtectedRoute from '../components/ProtectedRoute'
import { useAuth } from '../lib/auth-context'
import Assignment from '../pages/Assignment'
import Clients from '../pages/Clients'
import Companies from '../pages/Companies'
import CompanyDetail from '../pages/CompanyDetail'
import CommunicationDetail from '../pages/CommunicationDetail'
import Communications from '../pages/Communications'
import CommunicationThreadDetail from '../pages/CommunicationThreadDetail'
import CommunicationThreads from '../pages/CommunicationThreads'
import CommunicationTemplateForm from '../pages/CommunicationTemplateForm'
import CommunicationTemplates from '../pages/CommunicationTemplates'
import Contacts from '../pages/Contacts'
import Conversion from '../pages/Conversion'
import DashboardHome from '../pages/DashboardHome'
import FollowUpForm from '../pages/FollowUpForm'
import FollowUps from '../pages/FollowUps'
import LeadConvert from '../pages/LeadConvert'
import LeadDetail from '../pages/LeadDetail'
import LeadsList from '../pages/LeadsList'
import Lifecycle from '../pages/Lifecycle'
import Login from '../pages/Login'
import NewCommunication from '../pages/NewCommunication'
import NewCompany from '../pages/NewCompany'
import NewLead from '../pages/NewLead'
import NewOpportunity from '../pages/NewOpportunity'
import NotFound from '../pages/NotFound'
import Opportunities from '../pages/Opportunities'
import OpportunityDetail from '../pages/OpportunityDetail'
import Pipeline from '../pages/Pipeline'
import PublicHome from '../pages/PublicHome'
import Qualification from '../pages/Qualification'
import SurveyForm from '../pages/SurveyForm'
import Surveys from '../pages/Surveys'

// CRM dashboard routes are added here as submodules are implemented (see resources/specs/).
export default function AppRouter() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<PublicHome />} />
      </Route>

      <Route path="/login" element={<Login />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<DashboardLayout />}>
          <Route path="/dashboard" element={<DashboardHome />} />
          <Route path="/leads" element={<LeadsList />} />
          <Route path="/leads/new" element={<NewLead />} />
          <Route path="/leads/:id" element={<LeadDetail />} />
          <Route path="/leads/:id/convert" element={<LeadConvert />} />
          <Route path="/qualification" element={<Qualification />} />
          <Route path="/assignment" element={<Assignment />} />
          <Route path="/lifecycle" element={<Lifecycle />} />
          <Route path="/conversion" element={<Conversion />} />
          <Route path="/clients" element={<Clients />} />
          <Route path="/companies" element={<Companies />} />
          <Route path="/companies/new" element={<NewCompany />} />
          <Route path="/companies/:id" element={<CompanyDetail />} />
          <Route path="/contacts" element={<Contacts />} />
          <Route path="/pipeline" element={<Pipeline />} />
          <Route path="/opportunities" element={<Opportunities />} />
          <Route path="/opportunities/new" element={<NewOpportunity />} />
          <Route path="/opportunities/:id" element={<OpportunityDetail />} />
          <Route path="/communications" element={<Communications />} />
          <Route path="/communications/new" element={<NewCommunication />} />
          <Route path="/communications/threads" element={<CommunicationThreads />} />
          <Route path="/communications/threads/:threadId" element={<CommunicationThreadDetail />} />
          <Route path="/communications/templates" element={<CommunicationTemplates />} />
          <Route path="/communications/templates/new" element={<CommunicationTemplateForm />} />
          <Route path="/communications/templates/:id" element={<CommunicationTemplateForm />} />
          <Route path="/communications/:id" element={<CommunicationDetail />} />
          <Route path="/follow-ups" element={<FollowUps />} />
          <Route path="/follow-ups/new" element={<FollowUpForm />} />
          <Route path="/follow-ups/:id" element={<FollowUpForm />} />
          <Route path="/surveys" element={<Surveys />} />
          <Route path="/surveys/new" element={<SurveyForm />} />
          <Route path="/surveys/:id" element={<SurveyForm />} />
        </Route>
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}

// Single shared shell for every role (design system rule) — role comes from the session.
function DashboardLayout() {
  const { user } = useAuth()

  return <DashboardShell role={user?.role ?? 'salesrep'} />
}
