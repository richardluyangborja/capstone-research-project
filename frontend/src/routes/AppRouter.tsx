import { Route, Routes } from 'react-router-dom'
import DashboardShell from '../components/layout/DashboardShell'
import PublicLayout from '../components/layout/PublicLayout'
import ProtectedRoute from '../components/ProtectedRoute'
import { useAuth } from '../lib/auth-context'
import Companies from '../pages/Companies'
import CompanyDetail from '../pages/CompanyDetail'
import CommunicationDetail from '../pages/CommunicationDetail'
import Communications from '../pages/Communications'
import CommunicationThreadDetail from '../pages/CommunicationThreadDetail'
import CommunicationThreads from '../pages/CommunicationThreads'
import CommunicationTemplateForm from '../pages/CommunicationTemplateForm'
import CommunicationTemplates from '../pages/CommunicationTemplates'
import Contacts from '../pages/Contacts'
import DashboardHome from '../pages/DashboardHome'
import FollowUpForm from '../pages/FollowUpForm'
import FollowUps from '../pages/FollowUps'
import LeadConvert from '../pages/LeadConvert'
import LeadDetail from '../pages/LeadDetail'
import LeadsList from '../pages/LeadsList'
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
import PublicSurvey from '../pages/PublicSurvey'
import Reports from '../pages/Reports'
import Satisfaction from '../pages/Satisfaction'
import SurveyForm from '../pages/SurveyForm'
import SurveyInvitations from '../pages/SurveyInvitations'
import SurveyResponseDetail from '../pages/SurveyResponseDetail'
import SurveyResponses from '../pages/SurveyResponses'
import Surveys from '../pages/Surveys'
import {
  RedirectToAccount,
  RedirectToAccounts,
  RedirectToClientAccounts,
  RedirectToLeadView,
  RedirectToNewAccount,
} from './redirects'

// CRM dashboard routes are added here as submodules are implemented (see resources/specs/).
export default function AppRouter() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<PublicHome />} />
      </Route>

      <Route path="/login" element={<Login />} />

      {/* The client's survey page (module 05 slice S2): no session, no shell — the token in the
          link is the credential. */}
      <Route path="/survey/:token" element={<PublicSurvey />} />

      <Route element={<ProtectedRoute />}>
        {/* Module 01 route cleanup (spec 16 §Navigation and routes). The old paths stay alive
            so bookmarks and generated links keep working; they never render a page. */}
        <Route path="/companies" element={<RedirectToAccounts />} />
        <Route path="/companies/new" element={<RedirectToNewAccount />} />
        <Route path="/companies/:id" element={<RedirectToAccount />} />
        <Route path="/clients" element={<RedirectToClientAccounts />} />
        <Route path="/qualification" element={<RedirectToLeadView view="qualification" />} />
        <Route path="/assignment" element={<RedirectToLeadView view="unassigned" />} />
        <Route path="/lifecycle" element={<RedirectToLeadView view="aging" />} />
        <Route path="/conversion" element={<RedirectToLeadView view="conversion" />} />

        <Route element={<DashboardLayout />}>
          <Route path="/dashboard" element={<DashboardHome />} />
          <Route path="/leads" element={<LeadsList />} />
          <Route path="/leads/new" element={<NewLead />} />
          <Route path="/leads/:id" element={<LeadDetail />} />
          <Route path="/leads/:id/convert" element={<LeadConvert />} />
          <Route path="/accounts" element={<Companies />} />
          <Route path="/accounts/new" element={<NewCompany />} />
          <Route path="/accounts/:id" element={<CompanyDetail />} />
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
          <Route path="/survey-invitations" element={<SurveyInvitations />} />
          <Route path="/satisfaction" element={<Satisfaction />} />
          <Route path="/satisfaction/responses" element={<SurveyResponses />} />
          <Route path="/satisfaction/responses/:id" element={<SurveyResponseDetail />} />
          <Route path="/reports" element={<Reports />} />
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
