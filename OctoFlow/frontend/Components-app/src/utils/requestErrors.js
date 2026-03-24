export function extractRequestErrorMessage(requestError, fallbackMessage) {
  if (requestError && typeof requestError === 'object') {
    const responseMessage = requestError.response?.data?.message
    if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
      return responseMessage
    }

    const genericMessage = requestError.message
    if (typeof genericMessage === 'string' && genericMessage.trim() !== '') {
      return genericMessage
    }
  }

  return fallbackMessage
}
